<?php

namespace Statamic\Addons\StoryChief;

use Carbon\Carbon;
use Statamic\API\Str;
use Statamic\API\Form;
use Statamic\API\Page;
use Statamic\API\User;
use Statamic\API\Asset;
use Statamic\API\Crypt;
use Statamic\API\Entry;
use Statamic\API\Search;
use Statamic\API\Content;
use Statamic\API\Taxonomy;
use Statamic\API\GlobalSet;
use Statamic\API\Collection;
use Statamic\Extend\Extensible;
use Statamic\Data\Pages\PageCollection;
use Statamic\Data\Pages\Page as PageData;
use Illuminate\Support\Collection as IlluminateCollection;

class Fetch
{
    use Extensible;

    public $auth;
    public $deep;
    public $debug;
    public $depth;
    public $locale;
    public $nested;

    private $page;
    private $limit;
    private $offset;
    private $filter;
    private $taxonomy;

    private $index;
    private $query;
    private $isSearch;

    private $data;
    private $hasNextPage;
    private $totalResults;

  
    public function setParameters(array $params = [])
    {
        $params = collect($params);

        $this->deep = $this->checkDeep($params);
        $this->debug = bool(request('debug', $params->get('debug')));
        $this->depth = (int) (request('depth', $params->get('depth', null)));
        $this->locale = request('locale') ?: $params->get('locale') ?: default_locale();
        $this->nested = bool(request('nested', $params->get('nested', $this->getConfigBool('nested'))));

        $this->page = (int) (request('page') ?: $params->get('page', 1));
        $this->limit = (int) (request('limit') ?: $params->get('limit'));
        $this->offset = (int) (request('offset') ?: $params->get('offset'));
        $this->filter = request('filter') ?: $params->get('filter');
        $this->taxonomy = request('taxonomy') ?: $params->get('taxonomy');

        $this->index = request('index') ?: $params->get('index');
        $this->query = request('query') ?: $params->get('query');
    }

    /**
     * Fetch collection
     */
    public function collection($name = null)
    {
        $name = $name ?: request()->segment(4);

        if (! $collection = Collection::whereHandle($name)) {
            $message =  "Collection [$name] not found.";

            return request()->isJson() ? response($message, 404) : $message;
        }

        return $this->handle($collection->entries());
    }

    /**
     * Fetch single entry
     */
    public function entry($id = null)
    {
        if (is_null($id) && request()->segment(5)) {
            $id = request()->segment(4).'/'.request()->segment(5);
        } else {
            $id = $id ?: request()->segment(4);
        }

        if (Str::contains($id, '/')) {
            list($collection, $slug) = explode('/', $id);

            $entry = Entry::whereSlug($slug, $collection);
        } else {
            $entry = Entry::find($id);
        }

        return $this->handle($entry);
    }

    /**
     * Fetch single page
     */
    public function page($uri = null)
    {
        $uri = $uri ?: request()->segment(4);

        if (! $uri || $uri == 'home') {
            $page = Page::whereUri('/');
        } else {
            if (strpos('/'.request()->path(), $this->actionUrl('page')) !== false) {
                $uri = explode(ltrim($this->actionUrl('page'), '/'), request()->path())[1];
            }

            $uri = Str::ensureLeft(trim($uri), '/');

            if (! $page = Page::whereUri($uri)) {
                $message =  "Page [$uri] not found.";

                return request()->isJson() ? response($message, 404) : $message;
            }
        }

        return $this->handle($page);
    }

    /**
     * Fetch multiple pages
     */
    public function pages($pages = null)
    {
        $pages = $pages ?: request('pages');

        if (! is_null($pages) && ! is_array($pages)) {
            $pages = explode(',', $pages);
        }

        if ($pages) {
            $pages = collect_pages($pages)->map(function ($uri) {
                $uri = Str::ensureLeft(trim($uri), '/');

                return Page::whereUri($uri);
            })->filter();
        } else {
            if ($this->nested) {
                $pages = collect_pages([
                    Page::whereUri('/'),
                ]);
            } else {
                $pages = Page::all();
            }
        }

        return $this->handle($pages);
    }

    /**
     * Fetch single global
     */
    public function global($handle = null)
    {
        $handle = $handle ?: request()->segment(4);

        if (! $global = GlobalSet::whereHandle($handle)) {
            $message =  "Global [$handle] not found.";

            return request()->isJson() ? response($message, 404) : $message;
        }

        return $this->handle($global);
    }

    /**
     * Fetch multiple globals
     */
    public function globals($globals = null)
    {
        $globals = $globals ?: request('globals');

        if (! is_null($globals) && ! is_array($globals)) {
            $globals = explode(',', $globals);
        }

        if ($globals) {
            $globals = collect($globals)->map(function ($handle) {
                return GlobalSet::whereHandle($handle);
            })->filter();
        } else {
            $globals = GlobalSet::all();
        }

        return $this->handle($globals);
    }

    /**
     * Fetch search results
     */
    public function search()
    {
        $this->isSearch = true;

        $data = $this->index
            ? Search::in($this->index)->search($this->query)
            : Search::get($this->query);

        $data = $data->map(function ($item) {
            $content = Content::find($item['id']);

            if (method_exists($content, 'set')) {
                $content->set('search_score', $item['search_score']);
            }

            return $content;
        });

        return $this->handle($data);
    }

    /**
     * Fetch taxonomies
     */
    public function taxonomies()
    {
        $this->deep = false;

        $taxonomies = Taxonomy::all()->map(function ($taxonomy) {
            return $taxonomy->terms()->localize($this->locale);
        });

        return $this->handle($taxonomies);
    }

    /**
     * Fetch taxonomy
     */
    public function taxonomy($name = null)
    {
        $this->deep = false;

        // Reset param due to conflicting use of "taxonomy"
        $this->taxonomy = null;

        $name = $name ?: request()->segment(4);

        if (! $taxonomy = Taxonomy::whereHandle($name)) {
            $message =  "Taxonomy [$name] not found.";

            return request()->isJson() ? response($message, 404) : $message;
        }

        return $this->handle($taxonomy->terms()->localize($this->locale));
    }

    /**
     * Fetch multiple assets
     */
    public function assets($assets = null, $container = null)
    {
        $assets = $assets ?: request('assets');
        $container = $container ?: request('container');

        if (! is_null($assets) && ! is_array($assets)) {
            $assets = explode(',', $assets);
        }

        if ($container) {
            $assets = Asset::whereContainer($container);
        } elseif ($assets) {
            $assets = collect($assets)->map(function ($path) {
                return Asset::wherePath($path);
            })->filter();
        } else {
            $assets = Asset::all();
        }

        return $this->handle($assets);
    }

    /**
     * Fetch single asset
     */
    public function asset($id = null)
    {
        $id = $id ?: request('id');

        if (! $asset = Asset::find($id)) {
            $message = "Asset [$id] not found.";

            return response($message, 404);
        }

        return $this->handle($asset);
    }

    /**
     * Fetch single user
     */
    public function user($username = null)
    {
        $username = $username ?: request()->segment(4);

        if (! $user = User::whereUsername($username)) {
            $user = User::whereEmail($username);
        }

        if (! $user) {
            $message = "User [$username] not found.";

            return response($message, 404);
        }

        $data = collect($user->data())->except('password_hash')->toArray();

        return $this->handle($data);
    }

    /**
     * Fetch all users
     */
    public function users()
    {
        $users = User::all()->map(function ($user) {
            return collect($user->data())->except('password_hash');
        });

        return $this->handle($users);
    }

    /**
     * Fetch formset
     */
    public function formset($name = null)
    {
        $name = $name ?: request()->segment(4);

        if (! $formset = Form::get($name)) {
            $message =  "Formset [$name] not found.";

            return request()->isJson() ? response($message, 404) : $message;
        }

        $data = $formset->formset()->data();
        $data['params'] = Crypt::encrypt(['formset' => $name]);

        $result = collect(compact('data'));

        if ($this->debug) {
            dd($result);
        }

        return $result;
    }

    /**
     * Handle data
     */
    private function handle($data)
    {
        $this->data = $data;

        $this->taxonomizeData();
        $this->filterData();
        $this->setTotalResults();
        $this->offsetData();
        $this->limitData();

        if ($this->nested) {
            $this->processNestedPages();
        }

        if ($this->deep) {
            $this->processData();
        }

        $result = collect([
            'data' => $this->data,
            'page' => $this->page,
            'limit' => $this->limit,
            'offset' => $this->offset,
            'has_next_page' => $this->hasNextPage,
            'total_results' => $this->totalResults,
        ]);

        if ($this->debug) {
            dd($result);
        }

        return $result;
    }

    private function processNestedPages()
    {
        if ($this->data instanceof PageData) {
            $this->data = $this->addChildPagesToPage($this->data);
        }

        if ($this->data instanceof PageCollection) {
            $this->data = $this->data->map(function (PageData $page) {
                return $this->addChildPagesToPage($page);
            });
        }

        return $this;
    }

    /**
     * Get processed data
     */
    private function processData()
    {
        if (! $this->data instanceof IlluminateCollection) {
            $this->addTaxonomies($this->data);

            $this->data = $this->getLocalisedData($this->data);

            return $this;
        }

        $this->data = $this->data->map(function ($item) {
            if (method_exists($item, 'locale')) {
                $item->locale($this->locale);
            }

            $this->addTaxonomies($item);

            $data = $this->getLocalisedData($item);

            if ($this->isSearch) {
                $data = collect($item)->merge($data->get('id'));
            }

            return $data;
        });

        return $this;
    }

    /**
     * Handle taxonomy filters
     */
    private function taxonomizeData()
    {
        if ($this->taxonomy) {
            $this->data = $this->data->filter(function ($entry) {
                $taxonomies = collect(explode('|', $this->taxonomy));

                return $taxonomies->first(function ($key, $value) use ($entry) {
                    list($taxonomy, $term) = explode('/', $value);

                    return collect($entry->get($taxonomy))
                        ->contains(function ($key, $value) use ($term) {
                            return $term === slugify($value);
                        });
                });
            });
        }

        return $this;
    }

    /**
     * Handle filtering data
     */
    private function filterData()
    {
        if (! in_array($this->filter, ['published', 'unpublished'])) {
            return $this;
        }

        $filter = 'filter'.Str::ucfirst($this->filter);

        if ($this->data instanceof IlluminateCollection) {
            $this->data = $this->data->filter(function ($entry) use ($filter) {
                return $this->$filter($entry);
            })->filter();
        } else {
            $this->data = $this->$filter($this->data);
        }

        return $this;
    }

    /**
     * Filter unpublished content
     */
    private function filterUnpublished($data)
    {
        return method_exists($data, 'published')
            ? ($data->published() ? null : $data)
            : $data;
    }

    /**
     * Filter published content
     */
    private function filterPublished($data)
    {
        return method_exists($data, 'published')
            ? ($data->published() ? $data : null)
            : $data;
    }

    /**
     * Handle offsetting data
     */
    private function offsetData()
    {
        if ($this->data instanceof IlluminateCollection && $this->offset) {
            $this->data = $this->data->slice($this->offset);
        }

        return $this;
    }

    /**
     * Handle limiting data
     */
    private function limitData()
    {
        if ($this->data instanceof IlluminateCollection && $this->limit) {
            $this->data = $this->data->forPage($this->page, $this->limit);

            $this->setHasNextPage();
        }

        return $this;
    }

    /**
     * Add Taxonomy data
     */
    private function addTaxonomies($data)
    {
        if (method_exists($data, 'supplementTaxonomies')) {
            $data->supplementTaxonomies();
        }
    }

    /**
     * Check if next page is available
     */
    private function setHasNextPage()
    {
        $count = $this->offset + ($this->page * $this->limit);

        $this->hasNextPage = ($this->totalResults - $count) > 0;
    }

    /**
     *
     */
    private function setTotalResults()
    {
        if ($this->data instanceof IlluminateCollection) {
            $this->totalResults = $this->data->count();
        }
    }

    /**
     * Get localised data
     */
    private function getLocalisedData($rawData)
    {
        $data = $this->goDeep($rawData);

        if ($this->locale !== default_locale()) {
            $localisedData = $this->goDeep($rawData->dataForLocale($this->locale));

            $data = $data->merge($localisedData);
        }

        return $data;
    }

    /**
     * Fetch item data recursively
     */
    private function goDeep($item)
    {
        $item = collect($item)->map(function ($value, $key) {
            if (is_array($value)) {
                if ($key === 'children') {
                    return $value;
                }

                return collect($value)->map(function ($value) {
                    return $this->goDeep($value);
                });
            }

            return $this->isRelatable($value, $key) ? $this->relatedData($value, $key) : $value;
        });

        return $item->count() === 1 ? $item->first() : $item;
    }

    /**
     * Find related data
     */
    private function relatedData($value, $key)
    {
        if ($asset = Asset::find($value)) {
            return $asset->absoluteUrl();
        }

        if (Content::exists($value)) {
            return Content::find($value)->toArray();
        }

        if ($key === 'mount') {
            return $this->handle(Collection::whereHandle($value)->entries());
        }

        return $value;
    }

    /**
     * Check if value could be relatable data
     */
    private function isRelatable($value, $key)
    {
        if ($key === 'id' && ! $this->isSearch) {
            return false;
        }

        if (is_bool($value)) {
            return false;
        }

        if (is_float($value)) {
            return false;
        }

        if ($value instanceof Carbon) {
            return false;
        }

        return true;
    }

    private function checkDeep($params)
    {
        $param = request('deep', $params->get('deep'));

        return is_null($param) ? $this->getConfigBool('deep') : bool($param);
    }

    private function addChildPagesToPage(PageData $page)
    {
        $depth = $this->depth ?: null;

        $data = collect(
            Content::tree($page->uri(), $depth, false, false, null, $this->locale)
        )->map(function ($page) {
            return $this->processPage($page);
        })->all();

        return $page->set('children', $data);
    }

    private function processPage(array $page)
    {
        if (! empty($page['children'])) {
            $page['children'] = collect($page['children'])->map(function ($page) {
                return $this->processPage($page);
            })->all();
        }

        $this->addTaxonomies($page['page']);

        $page['page'] = $this->getLocalisedData($page['page']);

        return $page;
    }
}
