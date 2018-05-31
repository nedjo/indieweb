<?php

namespace Drupal\Tests\indieweb\Functional;

use Drupal\Core\Url;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\BrowserTestBase;

/**
 * Provides the base class for web tests for Indieweb.
 */
abstract class IndiewebBrowserTestBase extends BrowserTestBase {

  /**
   * Modules to enable for this test.
   *
   * @var string[]
   */
  public static $modules = [
    'block',
    'node',
    'indieweb',
    'indieweb_test',
  ];

  /**
   * An admin user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $adminUser;

  /**
   * A simple authenticated user.
   *
   * @var
   */
  protected $authUser;

  /**
   * Default title.
   *
   * @var string
   */
  protected $title_text = 'Hello indieweb';

  /**
   * Default body text.
   *
   * @var string
   */
  protected $body_text = 'Getting on the Indieweb is easy. Just install this module!';

  /**
   * Default summary text.
   *
   * @var string
   */
  protected $summary_text = 'A summary';

  /**
   * RSVP settings.
   *
   * @var string
   */
  protected $rsvp_settings = "yes|I am going!\nno|I can not go\nmaybe|I might go\ninterested|Interested, but will decide later!";

  /**
   * {@inheritdoc}
   */
  public function setUp() {
    parent::setUp();

    // Use administrator role, less hassle for browsing around.
    $this->adminUser = $this->drupalCreateUser([], NULL, TRUE);

    // Set front page to custom page instead of /user/login or /user/x
    \Drupal::configFactory()
      ->getEditable('system.site')
      ->set('page.front', '/indieweb-test-front')
      ->save();
  }

  /**
   * Creates several node types that are useful for micropub, posting etc.
   */
  protected function createNodeTypes() {

    $this->drupalLogin($this->adminUser);

    foreach (['like', 'bookmark', 'repost', 'reply', 'rsvp', 'event'] as $type) {
      $edit = ['name' => $type, 'type' => $type];
      $this->drupalPostForm('admin/structure/types/add', $edit, 'Save and manage fields');

      if ($type != 'event') {
        $edit = ['new_storage_type' => 'link', 'label' => 'Link', 'field_name' => $type . '_link'];
        $this->drupalPostForm('admin/structure/types/manage/' . $type . '/fields/add-field', $edit, 'Save and continue');
        $this->drupalPostForm(NULL, [], 'Save field settings');
        $this->drupalPostForm(NULL, [], 'Save settings');
        $edit = ['fields[field_' . $type . '_link][type]' => 'link_microformat'];
        $this->drupalPostForm('admin/structure/types/manage/' . $type . '/display', $edit, 'Save');
      }

      if ($type == 'rsvp') {
        $edit = ['new_storage_type' => 'list_string', 'label' => 'RSVP', 'field_name' => 'rsvp'];
        $this->drupalPostForm('admin/structure/types/manage/' . $type . '/fields/add-field', $edit, 'Save and continue');
        $this->drupalPostForm(NULL, ['settings[allowed_values]' => $this->rsvp_settings], 'Save field settings');
        $this->drupalPostForm(NULL, [], 'Save settings');
        $edit = ['fields[field_rsvp][type]' => 'list_microformat'];
        $this->drupalPostForm('admin/structure/types/manage/' . $type . '/display', $edit, 'Save');
      }

      if ($type == 'event') {
        $edit = ['new_storage_type' => 'daterange', 'label' => 'Date', 'field_name' => 'date'];
        $this->drupalPostForm('admin/structure/types/manage/' . $type . '/fields/add-field', $edit, 'Save and continue');
        $this->drupalPostForm(NULL, [], 'Save field settings');
        $this->drupalPostForm(NULL, [], 'Save settings');
      }
    }

    drupal_flush_all_caches();
  }

  /**
   * Enable webmention functionality in the UI.
   *
   * @param $edit
   */
  protected function enableWebmention($edit = []) {
    $edit += [
      'webmention_enable' => 1,
      'pingback_enable' => 1,
      'webmention_secret' => 'valid_secret',
      'webmention_endpoint' => 'https://webmention.io/example.com/webmention',
      'pingback_endpoint' => 'https://webmention.io/webmention?forward=http://example.com/webmention/notify',
    ];
    $this->drupalPostForm('admin/config/services/indieweb/webmention', $edit, 'Save configuration');
  }

  /**
   * Gets a minimum webmention payload.
   *
   * @param $node
   * @param string $secret
   *
   * @return array
   */
  protected function getWebmentionPayload($node, $secret = 'in_valid_secret') {

    $webmention = [
      'secret' => $secret,
      'source' => 'http://external.com/page/1',
      'target' => $node->toUrl('canonical', ['absolute' => TRUE])->toString(),
      'post' => [
        'type' => 'entry',
        'wm-property' => 'like-of',
        'content' => [
          'text' => 'Webmention from external.com'
        ],
      ],
    ];

    return $webmention;
  }

  /**
   * Sends a webmention request.
   *
   * @param $post
   * @param $json
   * @param $debug
   *
   * @return int $status_code
   */
  protected function sendWebmentionNotificationRequest($post = [], $json = TRUE, $debug = FALSE) {
    $notify_endpoint = Url::fromRoute('indieweb.webmention.notify', [], ['absolute' => TRUE])->toString();

    $client = \Drupal::httpClient();
    try {
      if ($json) {
        $response = $client->post($notify_endpoint, ['json' => $post]);
      }
      else {
        $response = $client->post($notify_endpoint, ['form_params' => $post]);
      }
      $status_code = $response->getStatusCode();
    }
    catch (\Exception $e) {
      $status_code = 400;
      if (strpos($e->getMessage(), '404 Not Found') !== FALSE) {
        $status_code = 404;
      }
      // Use following line if you want to debug the exception in tests.
      if ($debug) {
        debug($e->getMessage());
      }
    }

    return $status_code;
  }

  /**
   * Sends a micropub request.
   *
   * @param $post
   * @param $access_token
   * @param $debug
   * @param $type
   *   Either POST or JSON (form_params or json)
   *
   * @return int $status_code
   */
  protected function sendMicropubRequest($post, $access_token = 'is_valid', $debug = FALSE, $type = 'form_params') {
    $auth = 'Bearer ' . $access_token;
    $micropub_endpoint = Url::fromRoute('indieweb.micropub.endpoint', [], ['absolute' => TRUE])->toString();

    $client = \Drupal::httpClient();
    $headers = [
      'Accept' => 'application/json',
    ];

    // Access token is always in the headers when using Request from p3k.
    $headers['Authorization'] = $auth;

    try {
      $response = $client->post($micropub_endpoint, [$type => $post, 'headers' => $headers]);
      $status_code = $response->getStatusCode();
    }
    catch (\Exception $e) {

      // Default 400 on exception.
      $status_code = 400;

      if (strpos($e->getMessage(), '401') !== FALSE) {
        $status_code = 401;
      }

      if (strpos($e->getMessage(), '403') !== FALSE) {
        $status_code = 403;
      }

      if ($debug) {
        debug($e->getMessage());
      }
    }

    return $status_code;
  }

  /**
   * Assert node count.
   *
   * @param $count
   * @param $type
   */
  protected function assertNodeCount($count, $type) {
    $node_count = \Drupal::database()->query('SELECT count(nid) FROM {node} WHERE type = :type', [':type' => $type])->fetchField();
    self::assertEquals($count, $node_count);
  }

  /**
   * Get the last nid.
   *
   * @param $type
   *
   * @return mixed
   */
  protected function getLastNid($type = '') {
    if ($type) {
      return \Drupal::database()->query('SELECT nid FROM {node} WHERE type = :type ORDER by nid DESC LIMIT 1', [':type' => $type])->fetchField();
    }
    else {
      return \Drupal::database()->query('SELECT nid FROM {node} ORDER by nid DESC LIMIT 1')->fetchField();
    }
  }

  /**
   * Assert queue items.
   *
   * @param array $urls
   * @param $id
   */
  protected function assertQueueItems($urls = [], $id = NULL) {
    if ($urls) {
      $count = \Drupal::queue(WEBMENTION_QUEUE_NAME)->numberOfItems();
      $this->assertTrue($count == count($urls));

      // We use a query here, don't want to use a while loop. When there's
      // nothing in the queue yet, the table won't exist, so the query will
      // fail. When the first item is inserted, we'll be fine.
      try {
        $query = 'SELECT * FROM {queue} WHERE name = :name';
        $records = \Drupal::database()->query($query, [':name' => WEBMENTION_QUEUE_NAME]);
        foreach ($records as $record) {
          $data = unserialize($record->data);
          if (!empty($data['source']) && !empty($data['target']) && $id) {
            $this->assertTrue(in_array($data['target'], $urls));
            if ($data['entity_type_id'] == 'node') {
              $this->assertEquals($data['source'], Url::fromRoute('entity.node.canonical', ['node' => $id], ['absolute' => TRUE])->toString());
            }
            elseif ($data['entity_type_id'] == 'comment') {
              $this->assertEquals($data['source'], Url::fromRoute('entity.comment.canonical', ['comment' => $id], ['absolute' => TRUE])->toString());
            }
          }
        }
      }
      catch (\Exception $ignored) {
        //debug($ignored->getMessage());
      }
    }
    else {
      $count = \Drupal::queue(WEBMENTION_QUEUE_NAME)->numberOfItems();
      $this->assertFalse($count);
    }
  }

  /**
   * Truncate the queue.
   */
  protected function clearQueue() {
    \Drupal::database()->delete('queue')->condition('name', WEBMENTION_QUEUE_NAME)->execute();
    $this->assertQueueItems();
  }

  /**
   * Runs the queue. Both calls cron and drush.
   */
  protected function runWebmentionQueue() {
    module_load_include('inc', 'indieweb', 'indieweb.drush');
    drush_indieweb_send_webmentions();
    indieweb_cron();
  }

  /**
   * Asserts a syndication.
   *
   * @param $source_id
   * @param $url
   */
  protected function assertSyndication($source_id, $url) {
    $object = \Drupal::database()->query('SELECT * FROM {webmention_syndication} WHERE entity_id = :id', [':id' => $source_id])->fetchObject();
    if (isset($object->url)) {
      self::assertEquals($url, $object->url);
    }
    else {
      // explicit fail
      $this->assertTrue($object, 'no syndication found');
    }
  }

  /**
   * Create a syndication record.
   *
   * @param $url
   * @param string $entity_type_id
   * @param int $entity_id
   *
   * @throws \Exception
   */
  protected function createSyndication($url, $entity_type_id = 'node', $entity_id = 1) {
    $values = [
      'entity_id' => $entity_id,
      'entity_type_id' => $entity_type_id,
      'url' => $url
    ];

    \Drupal::database()
      ->insert('webmention_syndication')
      ->fields($values)
      ->execute();
  }

}
