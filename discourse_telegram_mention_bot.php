#!/usr/bin/env php
<?php

class DiscourseMentionBot
{
    private const LATEST_POST_ID_FILE = __DIR__ . '/last_post_id.txt';

    private string $discourseUrl;
    private string $telegramBotToken;
    private int $chatId;
    private array $keywords;
    private int $limit;

    public function __construct(
        string $discourseUrl,
        string $telegramBotToken,
        int $chatId,
        array $keywords,
        int $limit = 5000
    ) {
        $this->discourseUrl = rtrim($discourseUrl, '/');
        $this->telegramBotToken = $telegramBotToken;
        $this->chatId = $chatId;
        $this->keywords = $keywords;
        $this->limit = $limit;
    }

    public function run(): void
    {
        $lastPostId = $this->loadLatestPostId();
        echo 'Last Run Processed Post Id: ' . ($lastPostId ?? 'NONE') . PHP_EOL;

        $count = 0;
        $shouldRun = true;
        $shouldUpdateLatestId = true;
        $beforeId = null;

        $foundPosts = [];

        while ($shouldRun) {
            $latestPosts = $this->fetchPosts($beforeId);

            if ($shouldUpdateLatestId) {
                $this->saveLatestPostId($latestPosts[0]);

                $shouldUpdateLatestId = false;
            }

            foreach ($latestPosts as $latestPost) {
                $beforeId = $latestPost['id'];

                if ($beforeId === $lastPostId) {
                    echo 'Reached Last Run Processed Post Id Stopping' . PHP_EOL;

                    break 2;
                }

                if ($this->hasKeywords($latestPost)) {
                    $foundPosts[] = $latestPost;
                }
            }

            $count += count($latestPosts);
            echo 'Processed: ' . $count . PHP_EOL;

            if ($count > $this->limit) {
                $shouldRun = false;
            }

            sleep(1);
        }

        echo 'Found ' . count($foundPosts) . ' posts with keywords. Sending to Telegram Channel' . PHP_EOL;

        $foundPosts = array_reverse($foundPosts);
        foreach ($foundPosts as $foundPost) {
            $this->sendPost($foundPost);

            sleep(5);
        }

        echo 'Finished' . PHP_EOL . PHP_EOL;
    }

    private function hasKeywords(array $post): bool
    {
        // Some posts have 'raw' key missing so just to prevent errors we replace with empty body which will be skipped
        $text = $post['raw'] ?? '';

        foreach ($this->keywords as $keyword) {
            if (false !== stripos($text, $keyword)) {
                return true;
            }
        }

        return false;
    }

    private function sendPost(array $post): bool
    {
        $description = $this->formatDescription($post);
        $url = $this->buildPostUrl($post);

        $a = $this->sendMessageToChat($description);
        $b = $this->sendMessageToChat($url);

        // Make sure both posts were correct
        return $a && $b;
    }

    private function fetchPosts(int $beforeId = null): array
    {
        $url = $this->discourseUrl . '/posts.json';

        if (null !== $beforeId) {
            $url .= '?before=' . $beforeId;
        }

        $json = file_get_contents($url);
        $result = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return $result['latest_posts'] ?? [];
    }

    private function formatDescription(array $post): string
    {
        $topicTitle = $this->filterText($post['topic_title']);
        $username = $this->filterText($post['username']);
        $name = $this->filterText($post['name']);
        $message = substr(
            $this->filterText($post['raw'] ?? ''),
            0,
            100
        );

        $result = '*' . $topicTitle . '* by _' . $username . '_ (' . ($name ?? $username) . ')' . PHP_EOL . PHP_EOL;
        $result .= '```' . PHP_EOL . $message . PHP_EOL . '```' . PHP_EOL;

        return $result;
    }

    private function buildPostUrl(array $post): string
    {
        return $this->discourseUrl . '/t/' . $post['topic_slug'] . '/' . $post['topic_id'] . '/' . $post['post_number'];
    }

    private function sendMessageToChat(string $message): bool
    {
        $url = 'https://api.telegram.org/bot' . $this->telegramBotToken . '/sendMessage?text=' . urlencode($message) .
            '&chat_id=' . $this->chatId . '&parse_mode=Markdown';

        $json = file_get_contents($url);
        $result = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return ($result['ok'] ?? false) === true;
    }

    private function filterText(string $text): string
    {
        $text = preg_replace('/[^\x01-\x7F]/', '', $text);
        
        return preg_replace('/([_*`\[])/', '\\\\$1', $text);
    }

    private function saveLatestPostId(array $post): void
    {
        $id = $post['id'];

        file_put_contents(self::LATEST_POST_ID_FILE, $id);
    }

    private function loadLatestPostId(): ?int
    {
        if (false === file_exists(self::LATEST_POST_ID_FILE)) {
            return null;
        }

        $text = file_get_contents(self::LATEST_POST_ID_FILE);
        if (empty($text)) {
            return null;
        }

        return (int)$text;
    }
}

function getEnvVar(string $name): string
{
    $value = getenv($name);

    if (empty($value)) {
        throw new InvalidArgumentException(
            sprintf(
                'Env var "%s" is missing',
                $name
            )
        );
    }

    return $value;
}

$discourseUrl = 'https://forum.esk8.news';
$telegramBotToken = getEnvVar('TELEGRAM_BOT_TOKEN');
$chatId = getEnvVar('TELEGRAM_CHAT_ID');
$keywords = [
    '3dservisas',
    '3dserv',
    '3dser',
    '3ds',
    'fatboy',
    'fb230',
    'fb240',
    'fb260',
    'fb275',
    'fb320',
];

$bot = new DiscourseMentionBot($discourseUrl, $telegramBotToken, $chatId, $keywords, 100);
$bot->run();
