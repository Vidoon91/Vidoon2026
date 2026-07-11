<?php

if (!function_exists('get_runtime_settings_defaults')) {
    function get_runtime_settings_defaults() {
        return [
            'site' => [
                'base_url' => 'https://license.muyanshidai.com/',
            ],
            'account_levels' => [
                'free' => [
                    'label' => '免费订阅',
                    'days' => 0,
                    'daily_limit' => 3,
                ],
                'monthly' => [
                    'label' => '月订阅',
                    'days' => 30,
                    'daily_limit' => 100,
                ],
                'semiannual' => [
                    'label' => '半年订阅',
                    'days' => 183,
                    'daily_limit' => -1,
                ],
                'annual' => [
                    'label' => '年订阅',
                    'days' => 365,
                    'daily_limit' => -1,
                ],
            ],
        ];
    }
}

if (!function_exists('normalize_runtime_settings')) {
    function normalize_runtime_settings($settings, $defaults) {
        if (!is_array($settings)) {
            return $defaults;
        }

        if (!isset($settings['site']) || !is_array($settings['site'])) {
            $settings['site'] = $defaults['site'];
        }
        if (!isset($settings['site']['base_url']) || !is_string($settings['site']['base_url']) || trim($settings['site']['base_url']) === '') {
            $settings['site']['base_url'] = $defaults['site']['base_url'];
        }

        if (!isset($settings['account_levels']) || !is_array($settings['account_levels'])) {
            $settings['account_levels'] = $defaults['account_levels'];
        }

        foreach ($defaults['account_levels'] as $level => $defaultConfig) {
            if (!isset($settings['account_levels'][$level]) || !is_array($settings['account_levels'][$level])) {
                $settings['account_levels'][$level] = $defaultConfig;
                continue;
            }

            $config = $settings['account_levels'][$level];
            if (!isset($config['label']) || !is_string($config['label']) || trim($config['label']) === '') {
                $config['label'] = $defaultConfig['label'];
            }
            if (!isset($config['days']) || !is_numeric($config['days'])) {
                $config['days'] = $defaultConfig['days'];
            } else {
                $config['days'] = intval($config['days']);
            }
            if (!isset($config['daily_limit']) || !is_numeric($config['daily_limit'])) {
                $config['daily_limit'] = $defaultConfig['daily_limit'];
            } else {
                $config['daily_limit'] = intval($config['daily_limit']);
            }

            $settings['account_levels'][$level] = $config;
        }

        return $settings;
    }
}

if (!function_exists('get_runtime_settings')) {
    function get_runtime_settings() {
        static $settings = null;
        if ($settings !== null) {
            return $settings;
        }

        $defaults = get_runtime_settings_defaults();

        /*
         * 配置优先级说明：
         * 1. include/runtime_settings.php 中的 defaults 是兜底默认配置。
         * 2. 项目根目录 runtime_settings.json 如果存在，会作为运行时覆盖配置。
         * 3. JSON 缺失字段或字段类型异常时，会回退到 defaults 对应字段。
         *
         * 如果 runtime_settings.json 存在，其配置会覆盖 defaults。
         * 注意：线上如果存在 runtime_settings.json，只修改 defaults 不会覆盖
         * JSON 中已有的同名配置；需要同步修改 runtime_settings.json。
         */
        $settingsPath = dirname(__DIR__) . '/runtime_settings.json';
        if (!file_exists($settingsPath)) {
            $settings = normalize_runtime_settings($defaults, $defaults);
            return $settings;
        }

        $raw = file_get_contents($settingsPath);
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            $settings = normalize_runtime_settings($defaults, $defaults);
            return $settings;
        }

        $merged = array_replace_recursive($defaults, $decoded);
        $settings = normalize_runtime_settings($merged, $defaults);
        return $settings;
    }
}

if (!function_exists('get_runtime_site_value')) {
    function get_runtime_site_value($key, $default = null) {
        $settings = get_runtime_settings();
        $site = $settings['site'] ?? [];
        return $site[$key] ?? $default;
    }
}

if (!function_exists('get_account_level_runtime_config')) {
    function get_account_level_runtime_config($level) {
        $settings = get_runtime_settings();
        $levels = $settings['account_levels'] ?? [];
        return $levels[$level] ?? ($levels['free'] ?? []);
    }
}
