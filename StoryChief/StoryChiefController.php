<?php

namespace Statamic\Addons\StoryChief;

use Statamic\Extend\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Illuminate\Support\Arr;
use Statamic\Contracts\Data\Entries\Entry;
use Statamic\Contracts\Data\Users\User;
use Statamic\Exceptions\UuidExistsException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Statamic\API\Entry as EntryService;
use Statamic\API\Asset as AssetService;
use Statamic\API\Storage as StorageService;
use Statamic\API\Collection as CollectionService;
use Statamic\API\AssetContainer as AssetContainerService;
use Statamic\API\User as UserService;

class StoryChiefController extends Controller
{
    /**
     * Connection check
     *
     * @return JsonResponse
     * @throws UuidExistsException
     * @noinspection PhpUnused
     */
    public function postWebhook()
    {
        $payload = request()->all();
        $data = Arr::get($payload, 'data');
        $event = Arr::get($payload, 'meta.event');

        $this->validatePayload($payload);

        try {
            switch ($event) {
                case 'publish':
                    return $this->publishEntry($data);
                case 'update':
                    return $this->updateEntry($data);
                case 'delete':
                    return $this->deleteEntry($data);
                default:
                    return response()->json('ok');
            }
        } catch (\Exception $e) {
            \Log::error($e);
            return response()->json([
                'errors'    => 'Sorry, something went wrong.',
                'exception' => get_class($e),
                'message'   => $e->getMessage(),
                'trace'     => $e->getTrace()
            ], 500);
        }
    }


    /**
     * Create a new Entry
     *
     * @param array $data
     * @return JsonResponse
     * @throws UuidExistsException
     */
    protected function publishEntry($data)
    {
        $collection = $this->getConfig('collection')[0];
        $slug = $this->generateSlug($data, $collection);
        $body = $this->prepareBody($data, $collection);

        /** @var Entry $entry */
        $entry = EntryService::create($slug)
            ->collection($collection)
            ->with($body)
            ->date()
            ->save();

        return response()->json([
            'id'        => $entry->id(),
            'permalink' => $entry->absoluteUrl(),
        ]);
    }

    /**
     * Update an entry
     *
     * @param array $data
     * @return JsonResponse
     * @throws UuidExistsException
     */
    protected function updateEntry($data)
    {
        $id = Arr::get($data, 'external_id');
        $collection = $this->getConfig('collection')[0];
        $body = $this->prepareBody($data, $collection);

        if (!$entry = EntryService::find($id)) {
            return response()->json('Entry not found', 404);
        }

        foreach ($body as $key => $value) {
            $entry->set($key, $value);
        }

        $entry->save();

        return response()->json([
            'id'        => $entry->id(),
            'permalink' => $entry->absoluteUrl(),
        ]);
    }


    /**
     * Delete an entry
     *
     * @param $data
     * @return JsonResponse
     */
    protected function deleteEntry($data)
    {
        $id = Arr::get($data, 'external_id');

        if (!$entry = EntryService::find($id)) {
            return response()->json('Entry not found', 401);
        }

        $entry->delete();

        return response()->json('Entry deleted');
    }


    /**
     * @param array $body
     * @param string $collection
     * @return string
     */
    protected function generateSlug($body, $collection)
    {
        $slug = $slug_attempt = Arr::get($body, 'seo_slug', Str::slug($body['title'], '-'));

        $i = 1;
        while (EntryService::whereSlug($slug, $collection) !== null) {
            $slug = $slug_attempt . '-' . $i;
            $i++;
        }

        return $slug;
    }

    /**
     * Prepare the data for creating or editing an entry
     *
     * @param array $sc_data
     * @param string $collection
     * @return array
     * @noinspection PhpUnusedParameterInspection
     */
    protected function prepareBody($sc_data, $collection)
    {
        $collection_fields = Arr::get(CollectionService::whereHandle($collection)->fieldset()->toArray(), 'sections');

        $custom_fields = $this->getConfig('custom_fields', []);
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


            $body[$st_field] = $value;
        }

        // Map custom fields
        foreach ($custom_fields as $field) {
            $data = Arr::first($sc_data['custom_fields'], function ($value, $key) use ($field) {
                return $key['key'] == $field['sc_field'];
            });
            $body[$field['st_field']] = $data['value'];
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
     * @param string $uri
     * @param string $containerId
     * @return string
     */
    protected function createAsset($uri, $containerId)
    {
        $container = AssetContainerService::find($containerId);
        $asset = AssetService::create('img/sc/./')->container($container)->get();
        $image = file_get_contents($uri);
        $file_name = basename($uri);

        StorageService::put($file_name, $image);

        $asset->upload(new UploadedFile("site/storage/$file_name", $file_name));
        $asset->save();

        StorageService::delete($file_name);

        return $asset->uri();
    }

    /**
     * Fetches or creates a user
     *
     * @param string $value
     * @return User|null
     */
    protected function getUser($value)
    {
        if (filter_var($value, FILTER_VALIDATE_EMAIL)) {
            $user = UserService::whereEmail($value);
        } else {
            $user = UserService::whereUsername($value);
        }

        return $user;
    }

    /**
     * @param array $payload
     * @noinspection PhpComposerExtensionStubsInspection
     */
    protected function validatePayload($payload)
    {
        $given_mac = Arr::pull($payload, 'meta.mac');
        $calc_mac = hash_hmac('sha256', json_encode($payload), $this->getConfig('key'));

        if (!hash_equals($given_mac, $calc_mac)) {
            abort(401);
        }
    }
}
