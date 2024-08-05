<?php

namespace Grav\Plugin;

use Grav\Common\Plugin;
use Grav\Common\Uri;
use Grav\Common\Page\Page;
use RocketTheme\Toolbox\File\File;
use RocketTheme\Toolbox\Event\Event;
use Symfony\Component\Yaml\Yaml;

/**
 * Class WebmentionPlugin
 * @package Grav\Plugin
 */
class WebmentionPlugin extends Plugin
{
    protected $route;

    public static function getSubscribedEvents()
    {
        return [
            'onPluginsInitialized' => ['onPluginsInitialized', 0],
            'onFormProcessed' => ['onFormProcessed', 0],
            'onPageInitialized' => ['onPageInitialized', 0],
            'onAdminSave' => ['onAdminSave', 0]
        ];
    }

    public function onPluginsInitialized()
    {
        if ($this->isAdmin()) {
            return;
        }

        $config = $this->grav['config'];
        $enabled = [];

        // SENDER
        if ($config->get('plugins.webmention.sender.enabled')) {
            $uri = $this->grav['uri'];
            $path = $uri->path();
            $disabled = (array) $config->get('plugins.webmention.sender.ignore_routes');

            if (!in_array($path, $disabled)) {
                $clean = true;
                foreach ($disabled as $route) {
                    if (str_starts_with($path, $config->get('plugins.webmention.receiver.route'))) {
                        $clean = false;
                        break;
                    }
                }
                if ($clean) {
                    $eventTrigger = 'onAdminSave';
                    $enabled['onAdminSave'] = [
                        ['onAdminSave', 0]
                    ];
                }
            }
        }

        // RECEIVER
        if ($config->get('plugins.webmention.receiver.enabled')) {
            $uri = $this->grav['uri'];
            $route = $config->get('plugins.webmention.receiver.route');
            if ($route && str_starts_with($uri->path(), $route)) {
                $enabled['onPagesInitialized'][] = ['handleReceipt', 0];
            }
            $enabled['onTwigTemplatePaths'][] = ['onTwigTemplatePaths', 0];
            if ($config->get('plugins.webmention.receiver.expose_data')) {
                $enabled['onPagesInitialized'][] = ['exposeData', 0];
            }
            $advertise = $config->get('plugins.webmention.receiver.advertise_method');
            if ($advertise === 'header') {
                $enabled['onPagesInitialized'][] = ['advertiseHeader', 100];
            }
        }

        $this->enable($enabled);
    }

    public function exposeData(Event $e)
    {
        $config = $this->grav['config'];
        $datadir = $config->get('plugins.webmention.datadir');
        $datafile = $config->get('plugins.webmention.receiver.file_data');
        $root = DATA_DIR . $datadir . '/';
        $filename = $root . $datafile;
        $datafh = File::instance($filename);
        $data = Yaml::parse($datafh->content());
        $data = $data ?? [];
        $datafh->free();

        $node = null;
        $permalink = $this->grav['page']->permalink();
        if (array_key_exists($permalink, $data)) {
            $node = $data[$permalink];
        }
        if ($node !== null) {
            $config->set('plugins.webmention.data', $node);
        }
    }

    private function shouldAdvertise(Uri $uri, $config)
    {
        if (str_starts_with($uri->route(), $config->get('plugins.webmention.receiver.route'))) {
            return false;
        }

        $currentPath = implode('/', array_slice($uri->paths(), 0, -1)) . '/' . $uri->basename();
        $ignorePaths = $config->get('plugins.webmention.receiver.ignore_paths');
        foreach ($ignorePaths as $ignore) {
            if (str_ends_with($currentPath, $ignore)) {
                return false;
            }
        }

        return true;
    }

    private function sendTelegramNotification($message)
    {
        $botToken = '[insert token here]';
        $chatId = '[insert chat ID here]';
        $url = "https://api.telegram.org/bot$botToken/sendMessage";
        $data = [
            'chat_id' => $chatId,
            'text' => $message
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        $response = curl_exec($ch);

        if ($response === false) {
            $this->grav['log']->debug("Curl error: " . curl_error($ch));
        } else {
            $this->grav['log']->debug("Telegram response: " . $response);
        }

        curl_close($ch);
    }

    public function advertiseHeader(Event $e)
    {
        $uri = $this->grav['uri'];
        $config = $this->grav['config'];

        if (!$this->shouldAdvertise($uri, $config)) {
            return;
        }

        $base = $uri->base();
        $rcvrRoute = $config->get('plugins.webmention.receiver.route');
        $rcvrUrl = $base . $rcvrRoute;
        header('Link: <' . $rcvrUrl . '>; rel="webmention"', false);
    }

    public function onPageInitialized(Event $event)
    {
        $page = $this->grav['page'];
        $config = $this->grav['config'];
        $datadir = $config->get('plugins.webmention.datadir');
        $datafile = $config->get('plugins.webmention.receiver.file_data');
        $root = DATA_DIR . $datadir . '/';
        $filename = $root . $datafile;
        $datafh = File::instance($filename);
        $data = Yaml::parse($datafh->content());
        $datafh->free();

        $page_url = $this->normalizeUrl($page->url(true));

        if (isset($data[$page_url])) {
            $webmentions = $data[$page_url];
            foreach ($webmentions as &$webmention) {
                $webmention['date'] = date('Y-m-d H:i:s', $webmention['received']);
            }
            unset($webmention);
            $page->header()->webmentions = $webmentions;
        } else {
            $page->header()->webmentions = [];
        }
    }

    private function normalizeUrl($url)
    {
        $language = $this->grav['language'];
        $default_lang = $language->getDefault();
        $active_lang = $language->getActive();

        if ($active_lang !== $default_lang) {
            $url = str_replace("/{$active_lang}", '', $url);
        }

        return rtrim($url, '/');
    }

    public function handleReceipt(Event $e)
    {
        $config = $this->grav['config'];
        $datadir = $config->get('plugins.webmention.datadir');
        $datafile = $config->get('plugins.webmention.receiver.file_data');
        $root = DATA_DIR . $datadir . '/';

        if (!empty($_POST)) {
            $source = $_POST['source'] ?? null;
            $target = $_POST['target'] ?? null;
            $vouch = $config->get('plugins.webmention.vouch.enabled') && isset($_POST['vouch']) ? $_POST['vouch'] : null;

            if (is_null($source) || is_null($target)) {
                $this->returnJsonError(400, 'Missing source or target parameter.');
                return;
            }

            if (!filter_var($source, FILTER_VALIDATE_URL) || !filter_var($target, FILTER_VALIDATE_URL)) {
                $this->returnJsonError(400, 'Invalid source or target URL.');
                return;
            }

            if (!is_null($vouch) && !filter_var($vouch, FILTER_VALIDATE_URL)) {
                $this->returnJsonError(400, 'Invalid vouch URL.');
                return;
            }

            if (!str_starts_with($source, 'http://') && !str_starts_with($source, 'https://')) {
                $this->returnJsonError(400, 'Invalid source URL protocol.');
                return;
            }

            if (!str_starts_with($target, 'http://') && !str_starts_with($target, 'https://')) {
                $this->returnJsonError(400, 'Invalid target URL protocol.');
                return;
            }

            if (!is_null($vouch) && !str_starts_with($vouch, 'http://') && !str_starts_with($vouch, 'https://')) {
                $this->returnJsonError(400, 'Invalid vouch URL protocol.');
                return;
            }

            if ($source === $target) {
                $this->returnJsonError(400, 'Source and target cannot be the same.');
                return;
            }

            if (!is_null($vouch) && ($source === $vouch || $target === $vouch)) {
                $this->returnJsonError(400, 'Vouch URL cannot be the same as source or target.');
                return;
            }

            $accepts = true;
            $parts = parse_url($target);
            foreach ($config->get('plugins.webmention.receiver.ignore_paths') as $route) {
                if (str_ends_with($target, $route)) {
                    $accepts = false;
                    break;
                }
            }

            if ($parts['host'] !== $this->grav['uri']->host()) {
                $accepts = false;
            }

            if (!$accepts) {
                $this->returnJsonError(400, $this->grav['language']->translate('PLUGIN_WEBMENTION.MESSAGES.BAD_REQUEST_BADROUTE'));
                return;
            }

            if ($config->get('plugins.webmention.receiver.blacklist')) {
                $blacklisted = false;
                foreach ($config->get('plugins.webmention.receiver.blacklist') as $pattern) {
                    if (preg_match($pattern, $source)) {
                        $blacklisted = true;
                        break;
                    }
                }
                if ($blacklisted && !$config->get('plugins.webmention.receiver.blacklist_silently')) {
                    $this->returnJsonError(403, 'Source is blacklisted.');
                    return;
                }
            }

            if ($config->get('plugins.webmention.vouch.enabled') && $config->get('plugins.webmention.vouch.required')) {
                if ($vouch !== null) {
                    $vblisted = false;
                    if ($config->get('plugins.webmention.vouch.blacklist')) {
                        foreach ($config->get('plugins.webmention.vouch.blacklist') as $pattern) {
                            if (preg_match($pattern, $vouch)) {
                                $vblisted = true;
                                break;
                            }
                        }
                    }
                    if ($vblisted) {
                        $vouch = null;
                    }
                }

                if ($vouch === null) {
                    $iswhite = false;
                    if ($config->get('plugins.webmention.receiver.whitelist')) {
                        foreach ($config->get('plugins.webmention.receiver.whitelist') as $pattern) {
                            if (preg_match($pattern, $source)) {
                                $iswhite = true;
                                break;
                            }
                        }
                    }
                    if (!$iswhite) {
                        $this->returnJsonError(400, $this->grav['language']->translate('PLUGIN_WEBMENTION.MESSAGES.BAD_REQUEST_MISSING_VOUCH'));
                        return;
                    }
                }
            }

            $filename = $root . $datafile;
            $datafh = File::instance($filename);
            $datafh->lock();
            $data = Yaml::parse($datafh->content());
            $data = $data ?? [];

            $isdupe = false;
            if (array_key_exists($target, $data)) {
                foreach ($data[$target] as &$entry) {
                    if ($entry['source_url'] === $source) {
                        $isdupe = true;
                        $entry['vouch_url'] = $vouch;
                        $entry['source_mf2'] = null;
                        $entry['vouch_mf2'] = null;
                        $entry['lastchecked'] = null;
                        $entry['lastcode'] = null;
                        $entry['valid'] = null;
                        $entry['visible'] = false;

                        // Get the current page
                        $page = $this->grav['page'];

                        // Store mention data in page header
                        $page->header()->webmention = [
                            'mentioner' => $entry['source_url'],
                            'mentionee' => $target,
                            'date_received' => $entry['received'],
                            'valid' => $entry['valid'] === null ? 'Not yet checked' : ($entry['valid'] ? 'Yes' : 'No'),
                            'approved' => $entry['visible'] ? 'Yes' : 'No'
                        ];

                        // Save the updated page data
                        $page->save();

                        break; // Exit the loop after processing the duplicate entry
                    }
                }
                unset($entry);
            } else {
                $data[$target] = [];
            }

            $hash = md5($source . '|' . $target);
            if (!$isdupe) {
                $entry = [
                    'source_url' => $source,
                    'hash' => $hash,
                    'received' => time(),
                    'vouch_url' => $vouch,
                    'source_mf2' => null,
                    'vouch_mf2' => null,
                    'lastchecked' => null,
                    'lastcode' => null,
                    'valid' => null,
                    'visible' => false
                ];
                $data[$target][] = $entry;
            }

            $datafh->save(Yaml::dump($data));
            $datafh->free();

            // Send Telegram notification
            $message = "New webmention from: $source to: $target";
            $this->sendTelegramNotification($message);

            $success = true; // Set to false if any error occurs during processing
            $message = 'Webmention received successfully.'; // Default success message

            $this->returnJsonResponse([
                'source' => $source,
                'target' => $target,
                'success' => $success,
                'message' => $message
            ]);
        }
    }


    private function returnJsonError($code, $message)
    {
        header('Content-Type: application/json');
        http_response_code($code);
        echo json_encode(['success' => false, 'message' => $message]);
        exit;
    }

    private function returnJsonResponse($responseData)
    {
        header('Content-Type: application/json');
        echo json_encode($responseData);
        exit;
    }

    public function onAdminSave(Event $e)
    {
        $config = $this->grav['config'];
        $page = $e['object'];

        if ($page instanceof Page) {
            $content = $page->content();
//            $this->grav['log']->debug("WebmentionPlugin::onAdminSave - Content: " . htmlspecialchars($content));
            $this->sender($e, $content, $page);

            if ($config->get('plugins.webmention.sender.automatic')) {
                $this->notify($page);
            }
        }
    }

    private function sender(Event $e, $content, $page)
    {
//        $this->grav['log']->debug("WebmentionPlugin::sender - Function called");
        $pageid = $page->route();
        $config = $this->grav['config'];
        $datadir = $config->get('plugins.webmention.datadir');
        $datafile = $config->get('plugins.webmention.sender.file_data');
        $root = DATA_DIR . $datadir . '/';

        $filename = $root . $datafile;
        $datafh = File::instance($filename);
        $datafh->lock();
        $data = Yaml::parse($datafh->content());

        if (is_null($data) || !array_key_exists($pageid, $data)) {
            $data[$pageid] = [
                'lastmodified' => null,
                'permalink' => $page->permalink(),
                'links' => []
            ];
        }

        // Get cached links from page header
        $cachedLinks = $page->header()->webmention_links ?? [];

        // Extract links using DOMDocument:
        $doc = new \DOMDocument();
        @$doc->loadHTML($content); // Suppress warnings for malformed HTML
        $links = [];
        foreach ($doc->getElementsByTagName('a') as $link) {
            $href = $link->getAttribute('href');
            if ($href) {
                $links[] = $href;
            }
        }
//        $this->grav['log']->debug("WebmentionPlugin::sender - Found outgoing links: " . print_r($links, true)); // Log the raw links

        // Calculate the difference between current links and cached links
        $linksToAdd = array_diff($links, $cachedLinks);
        $linksToRemove = array_diff($cachedLinks, $links);

//        $this->grav['log']->debug("WebmentionPlugin::sender - Links to add: " . print_r($linksToAdd, true));
//        $this->grav['log']->debug("WebmentionPlugin::sender - Links to remove: " . print_r($linksToRemove, true));

        if (!empty($linksToAdd) || !empty($linksToRemove)) {
            $data[$pageid]['lastmodified'] = $page->modified();
            $data[$pageid]['permalink'] = $page->permalink();

            $blacklist = $config->get('plugins.webmention.sender.blacklist');

            // Process links to add
            foreach ($linksToAdd as $link) {
                $parsedUrl = parse_url($link);

                // Skip if it's an anchor link or if the hostname is the same (internal link)
                if (isset($parsedUrl['fragment']) || (isset($parsedUrl['host']) && $parsedUrl['host'] === $_SERVER['HTTP_HOST'])) {
//                    $this->grav['log']->debug("WebmentionPlugin::sender - Skipping link (anchor or internal): " . $link);
                    continue;
                }

                $clean = true;
                if ($blacklist !== null) {
                    foreach ($blacklist as $pattern) {
                        if (preg_match($pattern, $link)) {
                            $clean = false;
//                            $this->grav['log']->debug("WebmentionPlugin::sender - Link blacklisted: " . $link);
                            break;
                        }
                    }
                }
                if ($clean) {
                    $data[$pageid]['links'][] = [
                        'url' => $link,
                        'inpage' => true,
                        'lastnotified' => null,
                        'laststatus' => null,
                        'lastmessage' => null
                    ];
                }
            }

            // Process links to remove
            foreach ($data[$pageid]['links'] as $key => &$existingLink) {
                if (in_array($existingLink['url'], $linksToRemove)) {
                    unset($data[$pageid]['links'][$key]);
                }
            }
            unset($existingLink);

            // Cache the updated links in the page header
            $page->header()->webmention_links = $links;
            $page->save();
        }

        $datafh->save(Yaml::dump($data));
        $datafh->free();
        if ($config->get('plugins.webmention.sender.automatic')) {
            $this->notify($page);
        }
    }

    private function notify($page = null)
    {
//        $this->grav['log']->debug("WebmentionPlugin::notify - Function called");
        $config = $this->grav['config'];
        $datadir = $config->get('plugins.webmention.datadir');
        $datafile = $config->get('plugins.webmention.sender.file_data');
        $mapfile = $config->get('plugins.webmention.vouch.file_sender_map');
        $root = DATA_DIR . $datadir . '/';

        $filename = $root . $datafile;
        $datafh = File::instance($filename);
        $datafh->lock();
        $data = Yaml::parse($datafh->content());

        if (is_null($data)) {
            $datafh->free();
            return;
        }

        if ($config->get('plugins.webmention.vouch.enabled')) {
            $mapfilename = $root . $mapfile;
            if (file_exists($mapfilename)) {
                $mapfh = File::instance($mapfilename);
                $mapdata = Yaml::parse($mapfh->content());
                $mapfh->free();
            }
            $mapdata = $mapdata ?? [];
        }

        foreach ($data as $pageid => &$pagedata) {
            if (is_null($page) || $pageid === $page->route()) {
                foreach ($pagedata['links'] as &$link) {
                    if ($link['lastnotified'] === null) {
                        $vouch = null;
                        if ($config->get('plugins.webmention.vouch.enabled')) {
                            foreach ($mapdata as $pattern => $vouchurl) {
                                if (preg_match($pattern, $link['url'])) {
                                    $vouch = $vouchurl;
                                    break;
                                }
                            }
                        }

                        // Discover webmention endpoint using DOMDocument:
                        $supports = false; // Default to no support
                        $html = @file_get_contents($link['url']);
                        if ($html) { // Check if file_get_contents was successful
                            $doc = new \DOMDocument();
                            @$doc->loadHTML($html);
                            $linkTags = $doc->getElementsByTagName('link');
                            foreach ($linkTags as $linkTag) {
                                if ($linkTag->getAttribute('rel') === 'webmention') {
                                    $supports = true;
                                    $endpoint = $linkTag->getAttribute('href');
                                    break;
                                }
                            }
                        }

//                        $this->grav['log']->debug("WebmentionPlugin::notify - Checking Webmention support for: " . $link['url'] . " - Support: " . ($supports ? 'Yes' : 'No') . " - Endpoint: " . ($endpoint ?? 'Not found'));

                        if ($supports) {
                            // Send webmention using the discovered endpoint:
                            $result = $this->sendWebmention($page->permalink(), $link['url'], $endpoint, $vouch);

                            $link['lastnotified'] = time();
                            $link['laststatus'] = $result['code'];
                            $msg = "Headers:\n";
                            foreach ($result['headers'] as $key => $value) {
                                $msg .= $key . ': ' . $value . "\n";
                            }
                            $msg .= "\nBody:\n" . $result['body'];
                            $link['lastmessage'] = $msg;
                        } else {
                            $link['lastnotified'] = time();
                            $link['laststatus'] = null;
                            $link['lastmessage'] = 'Webmention support not advertised';
                        }
                    }
                }
                unset($link);
            }
        }
        unset($pagedata);

        $datafh->save(Yaml::dump($data));
        $datafh->free();
    }

    private function sendWebmention($source, $target, $endpoint, $vouch = null)
    {
        $data = [
            'source' => $source,
            'target' => $target
        ];
        if ($vouch !== null) {
            $data['vouch'] = $vouch;
        }
        $data = http_build_query($data);

        $opts = ['http' => [
            'method' => 'POST',
            'header' => 'Content-Type: application/x-www-form-urlencoded',
            'content' => $data,
        ]];

        $context = stream_context_create($opts);
        $response = @file_get_contents($endpoint, false, $context);

        if ($response === false) {
//            $this->grav['log']->debug("WebmentionPlugin::sendWebmention - Error sending webmention to: " . $endpoint);
            return [
                'code' => 0,
                'headers' => [],
                'body' => 'Error sending webmention'
            ];
        } else {
            $headers = $http_response_header; // Get the headers from the response
            $responseCode = substr($headers[0], 9, 3); // Extract the HTTP response code

//            $this->grav['log']->debug("WebmentionPlugin::sendWebmention - Webmention sent to: " . $endpoint . " - Response code: " . $responseCode);

            return [
                'code' => $responseCode,
                'headers' => $headers,
                'body' => $response
            ];
        }
    }

    public function onTwigTemplatePaths()
    {
        $this->grav['twig']->twig_paths[] = __DIR__ . '/templates';
    }
}
