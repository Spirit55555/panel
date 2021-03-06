<?php

namespace Pterodactyl\Tests\Integration\Api\Client\Server\Schedule;

use Pterodactyl\Models\Task;
use Illuminate\Http\Response;
use Pterodactyl\Models\Schedule;
use Pterodactyl\Models\Permission;
use Illuminate\Support\Facades\Bus;
use Pterodactyl\Jobs\Schedule\RunTaskJob;
use Pterodactyl\Tests\Integration\Api\Client\ClientApiIntegrationTestCase;

class ExecuteScheduleTest extends ClientApiIntegrationTestCase
{
    /**
     * Test that a schedule can be executed and is updated in the database correctly.
     *
     * @param array $permissions
     * @dataProvider permissionsDataProvider
     */
    public function testScheduleIsExecutedRightAway(array $permissions)
    {
        [$user, $server] = $this->generateTestAccount($permissions);

        Bus::fake();

        /** @var \Pterodactyl\Models\Schedule $schedule */
        $schedule = factory(Schedule::class)->create([
            'server_id' => $server->id,
        ]);

        $response = $this->actingAs($user)->postJson($this->link($schedule, '/execute'));
        $response->assertStatus(Response::HTTP_BAD_REQUEST);
        $response->assertJsonPath('errors.0.code', 'DisplayException');
        $response->assertJsonPath('errors.0.detail', 'Cannot process schedule for task execution: no tasks are registered.');

        /** @var \Pterodactyl\Models\Task $task */
        $task = factory(Task::class)->create([
            'schedule_id' => $schedule->id,
            'sequence_id' => 1,
            'time_offset' => 2,
        ]);

        $this->actingAs($user)->postJson($this->link($schedule, '/execute'))->assertStatus(Response::HTTP_ACCEPTED);

        Bus::assertDispatched(function (RunTaskJob $job) use ($task) {
            $this->assertSame($task->time_offset, $job->delay);
            $this->assertSame($task->id, $job->task->id);

            return true;
        });
    }

    /**
     * Test that the schedule is not executed if it is not currently active.
     */
    public function testScheduleIsNotExecutedIfNotActive()
    {
        [$user, $server] = $this->generateTestAccount();

        /** @var \Pterodactyl\Models\Schedule $schedule */
        $schedule = factory(Schedule::class)->create([
            'server_id' => $server->id,
            'is_active' => false,
        ]);

        $response = $this->actingAs($user)->postJson($this->link($schedule, "/execute"));

        $response->assertStatus(Response::HTTP_BAD_REQUEST);
        $response->assertJsonPath('errors.0.code', 'BadRequestHttpException');
        $response->assertJsonPath('errors.0.detail', 'Cannot trigger schedule exection for a schedule that is not currently active.');
    }

    /**
     * Test that a user without the schedule update permission cannot execute it.
     */
    public function testUserWithoutScheduleUpdatePermissionCannotExecute()
    {
        [$user, $server] = $this->generateTestAccount([Permission::ACTION_SCHEDULE_CREATE]);

        /** @var \Pterodactyl\Models\Schedule $schedule */
        $schedule = factory(Schedule::class)->create(['server_id' => $server->id]);

        $this->actingAs($user)->postJson($this->link($schedule, '/execute'))->assertForbidden();
    }

    /**
     * @return array
     */
    public function permissionsDataProvider(): array
    {
        return [[[]], [[Permission::ACTION_SCHEDULE_UPDATE]]];
    }
}
