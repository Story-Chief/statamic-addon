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
use Illuminate\Support\Collection as CollectionSupport;
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

    public function getCheck()
    {
        return response()->json('true');
    }


    public function getCollections()
    {
        $collections = [];
        foreach (Collection::all() as $collection) {
            array_push($collections, [
                'slug' => $collection->id(),
                'fieldset' => $collection->fieldset()->toArray()
            ]);
        }

        return response()->json(Collection::all());
    }

    
    public function getCollection()
    {
        $handle = request('handle');
        return response()->json(Collection::whereHandle($handle)->fieldset());
    }

    /**
     * Create a new Entry
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function postEntry()
    {
        $body = Arr::get(request()->all(), 'fields') ;
        $collection = Arr::get($body, 'collection');
        
        $slug = $this->generateSlug(Arr::get($body, 'slug', bin2hex(random_bytes(8))), $collection);
        $body['slug'] = $slug;

        $body = $this->prepareBody($body, $collection);

        $entry = Entry::create($slug)
            ->collection($collection)
            ->with($body)
            ->date()
            ->get();

        $entry->save();

        return response()->json(
            [
                'id' => $entry->id(),
                'url' => $entry->absoluteUrl(),
            ]
        );
    }
    
    public function putEntry()
    {
        $body = Arr::get(request()->all(), 'fields');
        $id = Arr::get(request()->all(), 'id');
        $collection = Arr::get($body, 'collection');

        if (!Entry::exists($id)) {
            return response()->json('Entry not found', 401);
        }

        $entry = Entry::find($id);
        if ($entry->get('slug') !== $body['slug']) {
            $slug = $this->generateSlug(Arr::get($body, 'slug', bin2hex(random_bytes(8))), $collection);
            $body['slug'] = $slug;
        }

        
        $body = $this->prepareBody($body, $collection);

        foreach ($body as $key => $value) {
            $entry->set($key, $value);
        }

        $entry->save();

        return response()->json(
            [
                'id' => $entry->id(),
                'url' => $entry->absoluteUrl(),
            ]
        );
    }


    public function deleteEntry()
    {
        $id = request('id');
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
        $tryslug = $slug;
        while (Entry::whereSlug($tryslug, $collection) !== null) {
            $tryslug = $slug.$i;
            $i++;
        }

        return $tryslug;
    }

    protected function prepareBody($body, $collection)
    {
        $fields = Arr::get(Collection::whereHandle($collection)->fieldset()->toArray(), 'sections');

        $collapsed = [];
        
        foreach ($fields as $key => $value) {
            if (is_array($value)) {
                array_push($collapsed, ...$value['fields']);
            }
        }
        foreach ($body as $key => $value) {
            $field = Arr::first($collapsed, function ($k, $v) use ($key) {
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


    protected function createAsset($uri, $containerId)
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

    protected function getUser($value)
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
