<?php

namespace Laravel\Scout\Tests\Feature\Engines;

use Algolia\AlgoliaSearch\SearchClient;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Testing\Assert;
use Laravel\Scout\Builder;
use Laravel\Scout\EngineManager;
use Laravel\Scout\Engines\Algolia3Engine;
use Laravel\Scout\Jobs\RemoveableScoutCollection;
use Laravel\Scout\Jobs\RemoveFromSearch;
use Mockery as m;
use Orchestra\Testbench\Attributes\WithConfig;
use Orchestra\Testbench\Attributes\WithMigration;
use Orchestra\Testbench\Concerns\WithWorkbench;
use Orchestra\Testbench\TestCase;
use Workbench\App\Models\Chirp;
use Workbench\App\Models\SearchableUser;
use Workbench\Database\Factories\ChirpFactory;
use Workbench\Database\Factories\SearchableUserFactory;

use function Orchestra\Testbench\after_resolving;

#[WithConfig('scout.driver', 'algolia3-testing')]
#[WithMigration]
class Algolia3EngineTest extends TestCase
{
    use LazilyRefreshDatabase;
    use WithWorkbench;

    protected $client;

    protected function defineEnvironment($app)
    {
        after_resolving($app, EngineManager::class, function ($manager) {
            $this->client = m::spy(SearchClient::class);

            $manager->extend('algolia3-testing', fn () => new Algolia3Engine($this->client, config('scout.soft_delete')));
        });

        $this->beforeApplicationDestroyed(function () {
            unset($this->client);
        });
    }

    public function test_update_adds_objects_to_index()
    {
        $model = SearchableUserFactory::new()->createQuietly();

        $engine = $this->app->make(EngineManager::class)->engine();

        $this->client->shouldReceive('initIndex')->once()->with('users')->andReturn($index = m::mock(stdClass::class));
        $index->shouldReceive('saveObjects')->once()->with([[
            'id' => $model->getKey(),
            'name' => $model->name,
            'email' => $model->email,
            'objectID' => $model->getScoutKey(),
        ]]);

        $engine->update(Collection::make([$model]));
    }

    public function test_delete_removes_objects_to_index()
    {
        $model = SearchableUserFactory::new()->createQuietly();

        $engine = $this->app->make(EngineManager::class)->engine();

        $this->client->shouldReceive('initIndex')->once()->with('users')->andReturn($index = m::mock(stdClass::class));
        $index->shouldReceive('deleteObjects')->once()->with([1]);

        $engine->delete(Collection::make([$model]));
    }

    public function test_delete_removes_objects_to_index_with_a_custom_search_key()
    {
        $model = ChirpFactory::new()->createQuietly([
            'scout_id' => 'my-algolia-key.5',
        ]);

        $engine = $this->app->make(EngineManager::class)->engine();

        $this->client->shouldReceive('initIndex')->once()->with('chirps')->andReturn($index = m::mock(Indexes::class));
        $index->shouldReceive('deleteObjects')->once()->with(['my-algolia-key.5']);

        $engine->delete(Collection::make([$model]));
    }

    public function test_delete_with_removeable_scout_collection_using_custom_search_key()
    {
        $model = ChirpFactory::new()->createQuietly([
            'scout_id' => 'my-algolia-key.5',
        ]);

        $engine = $this->app->make(EngineManager::class)->engine();

        $job = new RemoveFromSearch(RemoveableScoutCollection::make([$model]));

        $job = unserialize(serialize($job));

        $this->client->shouldReceive('initIndex')->once()->with('chirps')->andReturn($index = m::mock(stdClass::class));
        $index->shouldReceive('deleteObjects')->once()->with(['my-algolia-key.5']);

        $job->handle();
    }

    public function test_search_sends_correct_parameters_to_algolia()
    {
        $engine = $this->app->make(EngineManager::class)->engine();

        $this->client->shouldReceive('initIndex')->once()->with('users')->andReturn($index = m::mock(stdClass::class));
        $index->shouldReceive('search')->once()->with('zonda', [
            'numericFilters' => ['foo=1'],
        ])->once();

        $builder = new Builder(new SearchableUser, 'zonda');
        $builder->where('foo', 1);

        $engine->search($builder);
    }

    public function test_search_sends_correct_parameters_to_algolia_for_where_in_search()
    {
        $engine = $this->app->make(EngineManager::class)->engine();

        $this->client->shouldReceive('initIndex')->once()->with('users')->andReturn($index = m::mock(stdClass::class));
        $index->shouldReceive('search')->once()->with('zonda', [
            'numericFilters' => ['foo=1', ['bar=1', 'bar=2']],
        ]);

        $builder = new Builder(new SearchableUser, 'zonda');
        $builder->where('foo', 1)->whereIn('bar', [1, 2]);

        $engine->search($builder);
    }

    public function test_search_sends_correct_parameters_to_algolia_for_empty_where_in_search()
    {
        $engine = $this->app->make(EngineManager::class)->engine();

        $this->client->shouldReceive('initIndex')->once()->with('users')->andReturn($index = m::mock(stdClass::class));
        $index->shouldReceive('search')->once()->with('zonda', [
            'numericFilters' => ['foo=1', '0=1'],
        ]);

        $builder = new Builder(new SearchableUser, 'zonda');
        $builder->where('foo', 1)->whereIn('bar', []);
        $engine->search($builder);
    }

    public function test_map_correctly_maps_results_to_models()
    {
        $model = SearchableUserFactory::new()->createQuietly(['name' => 'zonda']);

        $engine = $this->app->make(EngineManager::class)->engine();

        $builder = m::mock(Builder::class);

        $results = $engine->map($builder, [
            'nbHits' => 1,
            'hits' => [
                ['objectID' => 1, 'id' => 1, '_rankingInfo' => ['nbTypos' => 0]],
            ],
        ], $model);

        $this->assertCount(1, $results);
        $this->assertEquals(['_rankingInfo' => ['nbTypos' => 0]], $results->first()->scoutMetaData());
        Assert::assertArraySubset(['id' => 1, 'name' => 'zonda'], $results->first()->toArray());
    }

    public function test_a_model_is_indexed_with_a_custom_algolia_key()
    {
        $model = ChirpFactory::new()->createQuietly([
            'scout_id' => 'my-algolia-key.1',
        ]);

        $engine = $this->app->make(EngineManager::class)->engine();

        $this->client->shouldReceive('initIndex')->once()->with('chirps')->andReturn($index = m::mock(stdClass::class));
        $index->shouldReceive('saveObjects')->once()->with([[
            'content' => $model->content,
            'objectID' => 'my-algolia-key.1',
        ]]);

        $engine->update(Collection::make([$model]));
    }

    public function test_a_model_is_removed_with_a_custom_algolia_key()
    {
        $model = ChirpFactory::new()->createQuietly([
            'scout_id' => 'my-algolia-key.1',
        ]);

        $engine = $this->app->make(EngineManager::class)->engine();

        $this->client->shouldReceive('initIndex')->once()->with('chirps')->andReturn($index = m::mock(stdClass::class));
        $index->shouldReceive('deleteObjects')->once()->with(['my-algolia-key.1']);

        $engine->delete(Collection::make([$model]));
    }

    public function test_flush_a_model_with_a_custom_algolia_key()
    {
        $model = ChirpFactory::new()->createQuietly([
            'scout_id' => 'my-algolia-key.1',
        ]);

        $engine = $this->app->make(EngineManager::class)->engine();

        $this->client->shouldReceive('initIndex')->once()->with('chirps')->andReturn($index = m::mock(stdClass::class));
        $index->shouldReceive('clearObjects')->once();

        $engine->flush(new Chirp);
    }

    public function test_update_empty_searchable_array_does_not_add_objects_to_index()
    {
        $_ENV['user.toSearchableArray'] = [];

        $engine = $this->app->make(EngineManager::class)->engine();

        $this->client->shouldReceive('initIndex')->once()->with('users')->andReturn($index = m::mock(stdClass::class));
        $index->shouldNotReceive('saveObjects');

        $engine->update(Collection::make([new SearchableUser]));

        unset($_ENV['user.toSearchableArray']);
    }

    #[WithConfig('scout.soft_delete', true)]
    public function test_update_empty_searchable_array_from_soft_deleted_model_does_not_add_objects_to_index()
    {
        $_ENV['chirp.toSearchableArray'] = [];

        $engine = $this->app->make(EngineManager::class)->engine();

        $this->client->shouldReceive('initIndex')->once()->with('chirps')->andReturn($index = m::mock(stdClass::class));
        $index->shouldNotReceive('saveObjects');

        $engine->update(Collection::make([new Chirp]));

        unset($_ENV['chirp.toSearchableArray']);
    }
}