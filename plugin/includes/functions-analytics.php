<?php
if (!defined('ABSPATH')) exit;

if (!function_exists('paguro_analytics_normalize_session_id')) {
    function paguro_analytics_normalize_session_id($value)
    {
        $value = trim((string) $value);
        if ($value === '') return '';
        $value = preg_replace('/[^A-Za-z0-9._:-]/', '', $value);
        if (!is_string($value) || strlen($value) < 8) return '';
        if (strlen($value) > 128) {
            $value = substr($value, 0, 128);
        }
        return $value;
    }
}

if (!function_exists('paguro_analytics_sanitize_string')) {
    function paguro_analytics_sanitize_string($value, $max_len = 500)
    {
        $value = wp_check_invalid_utf8((string) $value);
        $value = wp_strip_all_tags($value);
        $value = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value);
        $value = trim(preg_replace('/\s+/u', ' ', (string) $value));
        if ($value === '') return '';

        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            if (mb_strlen($value, 'UTF-8') > $max_len) {
                $value = mb_substr($value, 0, $max_len, 'UTF-8');
            }
        } elseif (strlen($value) > $max_len) {
            $value = substr($value, 0, $max_len);
        }

        return $value;
    }
}

if (!function_exists('paguro_analytics_redact_text')) {
    function paguro_analytics_redact_text($text)
    {
        $text = (string) $text;
        if ($text === '') return '';

        $patterns = [
            '/https?:\/\/\S+/iu' => '[url]',
            '/\b[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}\b/i' => '[email]',
            '/\b[A-Z]{2}\d{2}[A-Z0-9]{11,30}\b/i' => '[iban]',
            '/\btoken=([A-Za-z0-9]+)/i' => 'token=[redacted]',
            '/\b\d{8,}\b/' => '[number]',
        ];

        foreach ($patterns as $pattern => $replacement) {
            $text = preg_replace($pattern, $replacement, $text);
        }

        return paguro_analytics_sanitize_string($text, 800);
    }
}

if (!function_exists('paguro_analytics_sanitize_payload')) {
    function paguro_analytics_sanitize_payload($payload, $depth = 0)
    {
        if ($depth > 2) return [];
        if (!is_array($payload)) return [];

        $safe = [];
        $count = 0;
        foreach ($payload as $key => $value) {
            if ($count >= 40) break;
            $safe_key = sanitize_key((string) $key);
            if ($safe_key === '') continue;

            if (is_array($value)) {
                $safe[$safe_key] = paguro_analytics_sanitize_payload($value, $depth + 1);
            } elseif (is_bool($value)) {
                $safe[$safe_key] = (bool) $value;
            } elseif (is_int($value)) {
                $safe[$safe_key] = (int) $value;
            } elseif (is_float($value)) {
                $safe[$safe_key] = round((float) $value, 4);
            } elseif ($safe_key === 'session_id') {
                $safe[$safe_key] = paguro_analytics_normalize_session_id($value);
            } elseif (in_array($safe_key, ['message_text', 'reply_text', 'error_text'], true)) {
                $safe[$safe_key] = paguro_analytics_redact_text($value);
            } elseif (substr($safe_key, -4) === '_url' || $safe_key === 'referrer') {
                $safe[$safe_key] = esc_url_raw((string) $value);
            } else {
                $safe[$safe_key] = paguro_analytics_sanitize_string($value, 400);
            }

            $count++;
        }

        return $safe;
    }
}

if (!function_exists('paguro_analytics_detect_device_type')) {
    function paguro_analytics_detect_device_type($user_agent = '')
    {
        $user_agent = strtolower((string) $user_agent);
        if ($user_agent === '') return 'unknown';
        if (strpos($user_agent, 'tablet') !== false || strpos($user_agent, 'ipad') !== false) return 'tablet';
        if (strpos($user_agent, 'mobile') !== false || strpos($user_agent, 'android') !== false || strpos($user_agent, 'iphone') !== false) return 'mobile';
        return 'desktop';
    }
}

if (!function_exists('paguro_analytics_get_api_base_url')) {
    function paguro_analytics_get_api_base_url()
    {
        $api_url = trim((string) get_option('paguro_api_url', ''));
        if ($api_url === '') return '';

        $parsed = wp_parse_url($api_url);
        if (!is_array($parsed) || empty($parsed['host'])) return '';

        $scheme = !empty($parsed['scheme']) ? $parsed['scheme'] : 'https';
        $host = $parsed['host'];
        $port = !empty($parsed['port']) ? ':' . intval($parsed['port']) : '';
        $path = !empty($parsed['path']) ? rtrim($parsed['path'], '/') : '';
        $path = preg_replace('#/(chat|api)$#', '', $path);

        return $scheme . '://' . $host . $port . $path;
    }
}

if (!function_exists('paguro_analytics_get_endpoint_url')) {
    function paguro_analytics_get_endpoint_url()
    {
        $base_url = paguro_analytics_get_api_base_url();
        if ($base_url === '') return '';
        return rtrim($base_url, '/') . '/analytics/event';
    }
}

if (!function_exists('paguro_analytics_collect_context')) {
    function paguro_analytics_collect_context($context = [])
    {
        $context = is_array($context) ? $context : [];
        $server = $_SERVER;
        $defaults = [
            'source' => 'wordpress',
            'site_host' => wp_parse_url(home_url('/'), PHP_URL_HOST),
            'request_uri' => isset($server['REQUEST_URI']) ? paguro_analytics_sanitize_string($server['REQUEST_URI'], 300) : '',
            'referrer' => isset($server['HTTP_REFERER']) ? esc_url_raw((string) $server['HTTP_REFERER']) : '',
            'user_agent' => isset($server['HTTP_USER_AGENT']) ? paguro_analytics_sanitize_string($server['HTTP_USER_AGENT'], 300) : '',
        ];
        $merged = array_merge($defaults, $context);
        $merged['device_type'] = paguro_analytics_detect_device_type($merged['user_agent'] ?? '');
        return paguro_analytics_sanitize_payload($merged);
    }
}

if (!function_exists('paguro_analytics_track_event')) {
    function paguro_analytics_track_event($event_name, $payload = [], $context = [])
    {
        $event_name = sanitize_key(str_replace('-', '_', (string) $event_name));
        if ($event_name === '') return false;

        $endpoint_url = paguro_analytics_get_endpoint_url();
        if ($endpoint_url === '') return false;

        $payload = paguro_analytics_sanitize_payload(is_array($payload) ? $payload : []);
        $context = paguro_analytics_collect_context($context);
        $session_id = paguro_analytics_normalize_session_id($payload['session_id'] ?? ($context['session_id'] ?? ''));
        if ($session_id !== '') {
            $payload['session_id'] = $session_id;
        }

        if (!empty($payload['message_text']) && empty($payload['message_topic']) && function_exists('paguro_detect_language') && function_exists('paguro_extract_topic_hint')) {
            $lang = paguro_detect_language($payload['message_text']);
            $payload['message_topic'] = paguro_extract_topic_hint($payload['message_text'], $lang);
            $payload['lang'] = $lang;
        }

        $body = [
            'event_name' => $event_name,
            'session_id' => $session_id,
            'payload' => $payload,
            'context' => $context,
        ];

        $args = [
            'headers' => ['Content-Type' => 'application/json'],
            'body' => wp_json_encode($body),
            'timeout' => 2,
            'blocking' => false,
            'sslverify' => true,
        ];

        wp_remote_post($endpoint_url, $args);
        return true;
    }
}

if (!function_exists('paguro_handle_track_event')) {
    function paguro_handle_track_event()
    {
        if (!check_ajax_referer('paguro_chat_nonce', 'nonce', false)) {
            wp_send_json_error(['msg' => 'Nonce non valido.'], 403);
        }

        $event_name = sanitize_key(str_replace('-', '_', (string) wp_unslash($_POST['event_name'] ?? '')));
        $payload_raw = wp_unslash($_POST['payload'] ?? '');
        $payload = json_decode((string) $payload_raw, true);
        if (!is_array($payload)) {
            $payload = [];
        }

        $tracked = paguro_analytics_track_event($event_name, $payload, [
            'source' => 'frontend',
        ]);

        wp_send_json_success(['tracked' => (bool) $tracked]);
    }

    add_action('wp_ajax_paguro_track_event', 'paguro_handle_track_event');
    add_action('wp_ajax_nopriv_paguro_track_event', 'paguro_handle_track_event');
}
