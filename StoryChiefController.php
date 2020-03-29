<?php

namespace Statamic\Addons\StoryChief;

use Statamic\API\Arr;
use Statamic\API\Asset;
use Statamic\API\Entry;
use Statamic\API\Storage;
use Statamic\API\Collection;
use Statamic\Extend\Controller;
use Statamic\API\AssetContainer;
use Statamic\Addons\StoryChief\StoryChief;
use Statamic\API\User;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class StoryChiefController extends Controller
{
    private $sc;


    public function __construct(StoryChief $storyChief)
    {
        parent::__construct();
        $this->sc = $storyChief;
        $this->sc->checkAuth();
    }

    /**
     * Connection check
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function postWebhook()
    {
        $event = request('meta.event');

        switch ($event) {
            case 'test':
                return response()->json('true');
                break;
            case 'publish':
                return response()->json($this->publishEntry());
                break;
            case 'update':
                return response()->json($this->updateEntry());
                break;
            case 'delete':
                return response()->json($this->deleteEntry());
                break;
            default:
                return response()->json('true');
                break;
            break;
        }
    }


    /**
     * Create a new Entry
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function publishEntry()
    {
        $body = Arr::get(request()->all(), 'data') ;
        $collection = $this->getConfig('collection')[0];
        $slug = $this->generateSlug(Arr::get($body, 'seo_slug', bin2hex(random_bytes(8))), $collection);
        $body['seo_slug'] = $slug;
        $body = $this->prepareBody($body, $collection);

        $entry = Entry::create($slug)
            ->collection($collection)
            ->with($body)
            ->date()
            ->get();

        $entry->save();
        return[
                'id' => $entry->id(),
                'permalink' => $entry->absoluteUrl(),
            ];
    }
    
    /**
     * Update an entry
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateEntry()
    {
        $body = Arr::get(request()->all(), 'data');
        $id = Arr::get(request()->all(), 'data.external_id');
        $collection = $this->getConfig('collection')[0];

        if (!Entry::exists($id)) {
            return response()->json('Entry not found', 401);
        }

        $entry = Entry::find($id);
        if ($entry->get('slug') !== $body['seo_slug']) {
            $slug = $this->generateSlug(Arr::get($body, 'slug', bin2hex(random_bytes(8))), $collection);
            $body['seo_slug'] = $slug;
        }

        
        $body = $this->prepareBody($body, $collection);

        foreach ($body as $key => $value) {
            $entry->set($key, $value);
        }

        $entry->save();

        return
            [
                'id' => $entry->id(),
                'permalink' => $entry->absoluteUrl(),
            ]
        ;
    }


    /**
     * Delete an entry
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function deleteEntry()
    {
        $id = Arr::get(request()->all(), 'data.external_id');
        if (!Entry::exists($id)) {
            return response()->json('Entry not found', 401);
        }

        $entry = Entry::find($id);
        $entry->delete();


        return response()->json('Entry deleted');
    }


    protected function generateSlug($slug, $collection)
    {
        $i = 1;
        $tryslug = $this->getConfig('seo_slug') ? $this->getConfig('seo_slug') : $slug;
        while (Entry::whereSlug($tryslug, $collection) !== null) {
            $tryslug = $slug.$i;
            $i++;
        }

        return $tryslug;
    }

    /**
     * Prepare the data for creting or editing an entry
     *
     * @param array $body
     * @param String $collection
     * @return array
     */
    protected function prepareBody(array $sc_data, String $collection) : array
    {
        $collection_fields = Arr::get(Collection::whereHandle($collection)->fieldset()->toArray(), 'sections');
        
        $custom_fields = $this->getConfig('custom_fields');
        $fields_map = Arr::except($this->getConfig(), ['key', 'collection', 'custom_fields']);
        $collapsed_collection_fields = [];
        $body = [];
        
        foreach ($collection_fields as $key => $value) {
            if (is_array($value)) {
                array_push($collapsed_collection_fields, ...$value['fields']);
            }
        }

        // Map the data from SC
        foreach ($fields_map as $sc_field => $st_field) {
            $value = null;
            switch ($sc_field) {
                case 'author_email':
                    $value = $sc_data['author']['data']['email'];
                    break;
                case 'author_fullname':
                    $value = $sc_data['author']['data']['first_name'] . ' ' . $sc_data['author']['data']['last_name'];
                    break;
                case 'featured_image':
                    $value = $sc_data['featured_image']['data']['url'];
                    break;
                case 'tags':
                    $value = [];
                    foreach ($sc_data['tags']['data'] as $key => $val) {
                        array_push($value, $val['name']);
                    }
                    break;
                case 'categories':
                    $value = [];
                    foreach ($sc_data['categories']['data'] as $key => $val) {
                        array_push($value, $val['name']);
                    }
                    break;
                
                default:
                    $value = $sc_data[$sc_field];
                    break;
            }


            $body[$st_field] =  $value;
        }

        // Map custom fields
        foreach ($custom_fields as $field) {
            $data = Arr::first($sc_data['custom_fields'], function ($value, $key) use ($field) {
                return $key['key'] == $field['sc_field'];
            });
            $body[$field['st_field']] =  $data['value'];
        }


        foreach ($body as $key => $value) {
            $field = Arr::first($collapsed_collection_fields, function ($k, $v) use ($key) {
                return $v['name'] === $key;
            });

            switch ($field['type']) {
                case 'assets':
                    $body[$key] = $this->createAsset($value, $field['container']);
                    break;
                                    
                case 'users':
                    $body[$key] = $this->getUser($value)->id();
                    break;

                default:
                    break;
            }
        }

        return $body;
    }

    /**
     * Create an asset
     *
     * @param String $uri
     * @param String $containerId
     * @return string
     */
    protected function createAsset(String $uri, String $containerId) : string
    {
        $container = AssetContainer::find($containerId);
        $asset = Asset::create('img/sc/./')->container($container)->get();
        $image = file_get_contents($uri);
        $file_name = basename($uri);
        Storage::put($file_name, $image);

        $file = new UploadedFile("site/storage/$file_name", $file_name);


        $asset->upload($file);
        $asset->save();

        Storage::delete($file_name);

        return $asset->uri();
    }

    /**
     * Fetches or creates a user
     *
     * @param String $value
     * @return \Statamic\Contracts\Data\Users\User
     */
    protected function getUser(String $value)
    {
        if (filter_var($value, FILTER_VALIDATE_EMAIL)) {
            $user = User::whereEmail($value);
            if ($user) {
                return $user;
            }
        } else {
            $user = User::whereUsername($value);
            if ($user) {
                return $user;
            }
        }

        // If there's no user, create one
        if (filter_var($value, FILTER_VALIDATE_EMAIL)) {
            $email = $value;
            $first_name = strstr($value, '@', true);
            $username = strstr($value, '@', true);
        } else {
            $email = "$value@$value.com";
            $first_name = $value;
            $username = $value;
        }
        $user = User::create()
            ->username($username)
            ->email($email)
            ->with([
                'fist_name' => $first_name
            ])
            ->get();

        $user->save();

        return $user;
    }
}
