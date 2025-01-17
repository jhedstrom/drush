<?php

namespace Unish;

use Webmozart\PathUtil\Path;

/**
 * @group commands
 */
class QueueTest extends CommandUnishTestCase
{
    use TestModuleHelperTrait;

    public function testQueue()
    {
        $expected = 'aggregator_feeds,%items,"Drupal\Core\Queue\DatabaseQueue"';
        $sites = $this->setUpDrupal(1, true);

        // Enable aggregator since it declares a queue.
        $this->drush('pm-enable', ['aggregator']);

        $this->drush('queue-list');
        $output = $this->getOutput();
        $this->assertStringContainsString('aggregator_feeds', $output, 'Queue list shows the declared queue.');

        // We need user to own to the feed.
        $this->drush('user-create', ['example'], ['password' => 'password', 'mail' => "example@example.com"]);
        $this->drush('php-script', ['queue_script'], ['script-path' => __DIR__ . '/resources']);
        $this->drush('queue-list', [], ['format' => 'csv']);
        $output = $this->getOutputAsList();
        $this->assertEquals(str_replace('%items', 1, $expected), array_pop($output), 'Item was successfully added to the queue.');

        $this->drush('queue-run', ['aggregator_feeds']);
        $this->drush('queue-list', [], ['format' => 'csv']);
        $output = $this->getOutputAsList();
        $this->assertEquals(str_replace('%items', 0, $expected), array_pop($output), 'Queue item processed.');
    }

  /**
   * Tests the queue-delete command.
   */
    public function testQueueDelete()
    {
        $expected = 'aggregator_feeds,%items,"Drupal\Core\Queue\DatabaseQueue"';

        $sites = $this->setUpDrupal(1, true);

        // Enable aggregator since it declares a queue.
        $this->drush('pm-enable', ['aggregator']);

        // Add another item to the queue and make sure it was deleted.
        $this->drush('php-script', ['queue_script'], ['script-path' => __DIR__ . '/resources']);
        $this->drush('queue-list', [], ['format' => 'csv']);
        $output = $this->getOutputAsList();
        $this->assertEquals(str_replace('%items', 1, $expected), array_pop($output), 'Item was successfully added to the queue.');

        $this->drush('queue-delete', ['aggregator_feeds']);

        $this->drush('queue-list', [], ['format' => 'csv']);
        $output = $this->getOutputAsList();
        $this->assertEquals(str_replace('%items', 0, $expected), array_pop($output), 'Queue was successfully deleted.');
    }

  /**
   * Tests the RequeueException.
   */
    public function testRequeueException()
    {
        $sites = $this->setUpDrupal(1, true);

        // Copy the 'woot' module over to the Drupal site we just set up.
        $this->setupModulesForTests(['woot'], Path::join(__DIR__, '/../fixtures/modules'));

        // Enable woot module, which contains a queue worker that throws a
        // RequeueException.
        $this->drush('pm-enable', ['woot'], [], null, null, self::EXIT_SUCCESS);

        // Add an item to the queue.
        $this->drush('php-script', ['requeue_script'], ['script-path' => __DIR__ . '/resources']);

        // Check that the queue exists and it has one item in it.
        $expected = 'woot_requeue_exception,%items,"Drupal\Core\Queue\DatabaseQueue"';
        $this->drush('queue-list', [], ['format' => 'csv']);
        $output = $this->getOutput();
        $this->assertStringContainsString(str_replace('%items', 1, $expected), $output, 'Item was successfully added to the queue.');
        // Process the queue.
        $this->drush('queue-run', ['woot_requeue_exception']);

        // Check that the item was processed after being requeued once.
        // Here is the detailed workflow of what the above command did.
        // 1. Drush calls drush queue-run woot_requeue_exception.
        // 2. Drush claims the item. The worker sets a state variable (see below)
        // and throws the RequeueException.
        // 3. Drush catches the exception and puts it back in the queue.
        // 4. Drush claims the next item, which is the one that we just requeued.
        // 5. The worker finds the state variable, so it does not throw the
        // RequeueException this time (see below).
        // 6. Drush removes the item from the queue.
        // 7. Command finishes. The queue is empty.
        $this->drush('queue-list', [], ['format' => 'csv']);
        $output = $this->getOutput();
        $this->assertStringContainsString(str_replace('%items', 0, $expected), $output, 'Queue item processed after being requeued.');
    }

  /**
   * Tests that CustomExceptions do not hold up the queue.
   */
    public function testCustomException()
    {
        $this->setUpDrupal(1, true);

        // Copy the 'woot' module over to the Drupal site we just set up.
        $this->setupModulesForTests(['woot'], Path::join(__DIR__, '/../fixtures/modules'));

        // Enable woot module, which contains a queue worker that throws a
        // custom exception.
        $this->drush('pm-enable', ['woot'], [], null, null, self::EXIT_SUCCESS);

        // Add a couple of items to the queue.
        $this->drush('php-script', ['queue_custom_exception_script'], ['script-path' => __DIR__ . '/resources']);

        // Check that the queue exists and it has two items in it.
        $expected = 'woot_custom_exception,%items,"Drupal\Core\Queue\DatabaseQueue"';
        $this->drush('queue-list', [], ['format' => 'csv']);
        $output = $this->getOutput();
        $this->assertStringContainsString(str_replace('%items', 2, $expected), $output, 'Items were successfully added to the queue.');

        // Process the queue.
        $this->drush('queue-run', ['woot_custom_exception']);

        // Check that the item was processed after being requeued once.
        // Here is the detailed workflow of what the above command did. Note
        // there are two items in the queue when we begin.
        // 1. Drush calls drush queue-run woot_custom_exception.
        // 2. Drush claims the item. The worker sets a state variable (see below)
        // and throws a CustomException.
        // 3. Drush catches the exception and skips the item.
        // 4. Drush claims the second item.
        // 5. The worker finds the state variable, so it does not throw the
        // CustomException this time (see below).
        // 6. Drush removes the second item from the queue.
        // 7. Command finishes. The queue is left with the first item, which was
        // skipped.
        $this->drush('queue-list', [], ['format' => 'csv']);
        $output = $this->getOutput();
        $this->assertStringContainsString(str_replace('%items', 1, $expected), $output, 'Last queue item processed after first threw custom exception.');
    }
}
