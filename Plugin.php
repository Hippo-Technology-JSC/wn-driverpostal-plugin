<?php

namespace Hippo\DriverPostal;

use Event;
use Hippo\DriverPostal\Transports\PostalTransport;
use System\Classes\PluginBase;
use System\Models\MailSetting;
use Postal\Client as PostalClient;

/**
 * Postal Plugin Information File
 */
class Plugin extends PluginBase
{
    const MODE_POSTAL = 'postal';

    public function pluginDetails()
    {
        return [
            'name'        => 'hippo.driverpostal::lang.plugin.name',
            'description' => 'hippo.driverpostal::lang.plugin.description',
            'homepage'    => 'https://github.com/Hippo-Technology-JSC/wn-driverpostal-plugin',
            'author'      => 'Hippo Techno',
            'icon'        => 'icon-leaf',
        ];
    }

    public function register()
    {
        Event::listen('mailer.beforeRegister', function ($mailManager) {
            $settings = \System\Models\MailSetting::instance();

            if ($settings->send_mode !== self::MODE_POSTAL) {
                return;
            }

            $base = rtrim($settings->postal_base_uri ?: \Config::get('services.' . self::MODE_POSTAL . '.base_uri', 'http://localhost:5001'), '/');
            $key  = $settings->postal_api_key ?: \Config::get('services.' . self::MODE_POSTAL . '.api_key', '');
            $timeout = (int) \Config::get('services.' . self::MODE_POSTAL . '.timeout', 10);

            \Config::set('mail.default', self::MODE_POSTAL);
            \Config::set('mail.mailers.' . self::MODE_POSTAL . '.transport', self::MODE_POSTAL);

            $mailManager->extend(self::MODE_POSTAL, function () use ($base, $key, $timeout) {
                if (!$key) {
                    throw new \RuntimeException('Postal: thiếu API key trong Settings → Mail Configuration.');
                }

                // Dùng SDK Postal
                $client = new PostalClient($base, $key);

                return new PostalTransport(
                    $client,
                    $base,
                    null,
                    app('log') ?? null
                );
            });
        });
    }

    public function boot()
    {
        $this->extendMailSettings();
        $this->extendMailForm();
    }

    /**
     * Extend the mail settings model to add support for Postal
     */
    protected function extendMailSettings()
    {
        MailSetting::extend(function ($model) {
            $model->bindEvent('model.beforeValidate', function () use ($model) {
                $model->rules['postal_api_key']  = 'required_if:send_mode,' . self::MODE_POSTAL;
                $model->rules['postal_base_uri'] = 'required_if:send_mode,' . self::MODE_POSTAL . '|url';
            });

            // default (có thể lấy từ .env)
            $model->postal_api_key  = config('services.' . self::MODE_POSTAL . '.api_key', env('POSTAL_API_KEY'));
            $model->postal_base_uri = config('services.' . self::MODE_POSTAL . '.base_uri', env('POSTAL_BASE_URI', 'http://localhost:5001'));
        });
    }

    /**
     * Extend the mail form to add support for Postal
     */
    protected function extendMailForm()
    {
        Event::listen('backend.form.extendFields', function ($widget) {
            if (!$widget->getController() instanceof \System\Controllers\Settings) return;
            if (!$widget->model instanceof MailSetting) return;

            $field = $widget->getField('send_mode');
            $field->options(array_merge($field->options(), [self::MODE_POSTAL => 'Hippo Postal (HTTP API)']));

            $widget->addTabFields([
                'postal_base_uri' => [
                    'label'        => 'Postal Base URI',
                    'placeholder'      => 'hippo.driverpostal::lang.postal_base_uri_placeholder',
                    'commentAbove' => 'hippo.driverpostal::lang.postal_base_uri_comment',
                    'commentHtml' => true,
                    'tab'          => 'system::lang.mail.general',
                    'span'         => 'left',
                    'trigger'      => [
                        'action'    => 'show',
                        'field'     => 'send_mode',
                        'condition' => 'value[' . self::MODE_POSTAL . ']',
                    ],
                ],
                'postal_api_key' => [
                    'label'        => 'Postal Server API Key',
                    'placeholder'      => 'hippo.driverpostal::lang.postal_key_placeholder',
                    'commentAbove' => 'hippo.driverpostal::lang.postal_key_comment',
                    'tab'          => 'system::lang.mail.general',
                    'type'         => 'sensitive',
                    'span'         => 'right',
                    'trigger'      => [
                        'action'    => 'show',
                        'field'     => 'send_mode',
                        'condition' => 'value[' . self::MODE_POSTAL . ']',
                    ],
                ],
            ]);
        });
    }
}
