<?php

namespace Hippo\DriverPostal;

use App;
use ApplicationException;
use Backend\Classes\WidgetBase;
use Backend\FormWidgets\FileUpload;
use Backend\FormWidgets\MarkdownEditor;
use Backend\FormWidgets\RichEditor;
use Backend\Widgets\MediaManager;
use Event;
use Lang;
use Request;
use Response;
use Symfony\Component\Mime\MimeTypes;
use System\Classes\PluginBase;
use System\Models\MailSetting;
use SystemException;
use Validator;
use Hippo\DriverPostal\Behaviors\StreamS3Uploads;
use Hippo\DriverPostal\Services\PostalTransport;
use Winter\Storm\Database\Attach\File as FileModel;
use Winter\Storm\Exception\ValidationException;

/**
 * Postal Plugin Information File
 */
class Plugin extends PluginBase
{
    const MODE_POSTAL = 'postal';

    public function pluginDetails()
    {
        return [
            'name'        => 'hippo.postal::lang.plugin.name',
            'description' => 'hippo.postal::lang.plugin.description',
            'homepage'    => 'https://github.com/Hippo-Technology-JSC/wn-driverpostal-plugin',
            'author'      => 'Hippo Techno',
            'icon'        => 'icon-leaf',
        ];
    }

    public function register()
    {
        Event::listen('mailer.beforeRegister', function ($mailManager) {
            $settings = MailSetting::instance();

            if ($settings->send_mode === self::MODE_POSTAL) {
                $config = App::make('config');

                $config->set('mail.default', 'postal');
                $config->set('mail.mailers.postal.transport', 'postal');

                $config->set('services.postal.base_uri',  $settings->postal_base_uri ?: env('POSTAL_BASE_URI', 'http://localhost:5001'));
                $config->set('services.postal.api_key', $settings->postal_api_key ?: env('POSTAL_API_KEY'));
                $config->set('services.postal.timeout',   10);
            }

            $mailManager->extend('postal', function ($app) {
                $cfg = config()->get('services.postal');
                return new PostalTransport(
                    $cfg['base_uri']   ?? 'http://localhost:5001',
                    $cfg['api_key'] ?? '',
                    $cfg['timeout']    ?? 10
                );
            });
        });
    }

    public function boot()
    {
        $this->extendMailSettings();
        $this->extendMailForm();

        // Add support for S3 streamed uploads
        $this->extendUploadableWidgets();
        $this->processUploadableWidgetUploads();
        $this->processFileUploadWidgetUploads();
    }

    /**
     * Extend the mail settings model to add support for Postal
     */
    protected function extendMailSettings()
    {
        MailSetting::extend(function ($model) {
            $model->bindEvent('model.beforeValidate', function () use ($model) {
                $model->rules['postal_api_key']  = 'required_if:send_mode,' . self::MODE_POSTAL;
                $model->rules['postal_base_url'] = 'required_if:send_mode,' . self::MODE_POSTAL . '|url';
            });

            // default (có thể lấy từ .env)
            $model->postal_api_key  = config('services.driverpostal.api_key', env('POSTAL_API_KEY'));
            $model->postal_base_url = config('services.driverpostal.base_url', env('POSTAL_BASE_URL', 'http://localhost:5001'));
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
                'postal_base_url' => [
                    'label'        => 'Postal Base URL',
                    'placeholder'      => 'hippo.postal::lang.postal_base_url_placeholder',
                    'commentAbove' => 'hippo.postal::lang.postal_base_url_comment',
                    'commentHtml' => true,
                    'tab'          => 'system::lang.mail.general',
                    'span'         => 'left',
                    'trigger'      => [
                        'action'    => 'show',
                        'field'     => 'send_mode',
                        'condition' => 'value[postal]',
                    ],
                ],
                'postal_api_key' => [
                    'label'        => 'Postal Server API Key',
                    'placeholder'      => 'hippo.postal::lang.postal_key_placeholder',
                    'commentAbove' => 'hippo.postal::lang.postal_key_comment',
                    'tab'          => 'system::lang.mail.general',
                    'type'         => 'sensitive',
                    'span'         => 'right',
                    'trigger'      => [
                        'action'    => 'show',
                        'field'     => 'send_mode',
                        'condition' => 'value[postal]',
                    ],
                ],
            ]);

            // Nếu trước đây đã có các field cũ, ẩn chúng:
            $widget->removeField('postal_secret');
            $widget->removeField('postal_region');
            $widget->removeField('postal_key'); // nếu bạn đổi tên
        });
    }

    /**
     * Extend the uploadable Widgets to support streaming file uploads directly to S3
     */
    protected function extendUploadableWidgets()
    {
        $addBehavior = function (WidgetBase $widget): void {
            $widget->extendClassWith(StreamS3Uploads::class);

            if ($widget->streamUploadsIsEnabled()) {
                $widget->addJs('/plugins/hippo/driverpostal/assets/js/build/stream-file-uploads.js');
            }
        };

        MediaManager::extend($addBehavior);
        FileUpload::extend($addBehavior);
        RichEditor::extend($addBehavior);
    }

    /**
     * Hook into the backend.widgets.uploadable.onUpload event to process streamed file uploads
     */
    protected function processUploadableWidgetUploads()
    {
        Event::listen('backend.widgets.uploadable.onUpload', function (WidgetBase $widget): ?\Illuminate\Http\Response {
            if (!$widget->streamUploadsIsEnabled()) {
                return null;
            }

            // Check if the request came from our StreamFileUploads.js script
            if (!Request::has(['uuid', 'key', 'bucket', 'name', 'content_type'])) {
                return null;
            }

            try {
                /**
                 * Expects the following input data:
                 * - uuid: The unique identifier of uploaded file on S3
                 * - name: The original name of the uploaded file
                 * - path: The path to put the uploaded file (relative to the media folder and only takes effect if $widget->uploadPath is not set)
                 */
                $uploadedPath = 'tmp/' . Request::input('uuid');
                $originalName = Request::input('name');

                $fileName = $widget->validateMediaFileName(
                    $originalName,
                    strtolower(pathinfo($originalName, PATHINFO_EXTENSION))
                );

                $disk = $widget->uploadableGetDisk();

                // Check if the upload succeeded
                if (!$disk->exists($uploadedPath)) {
                    throw new ApplicationException(Lang::get('hippo.postal::lang.stream_uploads.upload_failed'));
                }

                $targetPath = $widget->uploadableGetUploadPath($fileName);

                $disk->move($uploadedPath, $targetPath);

                /**
                 * @event media.file.streamedUpload
                 * Called after a file is uploaded via streaming
                 *
                 * Example usage:
                 *
                 *     Event::listen('media.file.streamedUpload', function ((\Backend\Widgets\MediaManager) $mediaWidget, (string) &$path) {
                 *         \Log::info($path . " was upoaded.");
                 *     });
                 *
                 * Or
                 *
                 *     $mediaWidget->bindEvent('file.streamedUpload', function ((string) &$path) {
                 *         \Log::info($path . " was uploaded");
                 *     });
                 *
                 */
                $widget->fireSystemEvent('media.file.streamedUpload', [&$targetPath]);

                $response = Response::make([
                    'link' => $widget->uploadableGetUploadUrl($targetPath),
                    'result' => 'success'
                ]);
            } catch (\Throwable $ex) {
                throw new ApplicationException($ex->getMessage());
            }

            return $response;
        });
    }

    /**
     * Hook into the backend.formwidgets.fileupload.onUpload event to process streamed file uploads
     */
    protected function processFileUploadWidgetUploads()
    {
        Event::listen('backend.formwidgets.fileupload.onUpload', function (FileUpload $widget, FileModel $model): ?string {
            if (!$widget->streamUploadsIsEnabled()) {
                return null;
            }

            // Check if the request came from our StreamFileUploads.js script
            if (!Request::has(['uuid', 'key', 'bucket', 'name', 'content_type'])) {
                return null;
            }

            /**
             * Expects the following input data:
             * - uuid: The unique identifier of uploaded file on S3
             * - name: The original name of the uploaded file
             */
            $disk = $model->getDisk();
            $path = 'tmp/' . Request::input('uuid');
            $name = Request::input('name');

            // Check if the upload succeeded
            if (!$disk->exists($path)) {
                throw new ApplicationException(Lang::get('hippo.postal::lang.stream_uploads.upload_failed'));
            }

            $rules = ['size' => 'max:' . $model::getMaxFilesize()];

            if ($fileTypes = $widget->getAcceptedFileTypes()) {
                $rules['name'] = 'ends_with:' . $fileTypes;
            }

            if ($widget->mimeTypes) {
                $mimeType = new MimeTypes();
                $mimes = [];
                foreach (explode(',', $widget->mimeTypes) as $item) {
                    if (str_contains($item, '/')) {
                        $mimes[] = $item;
                        continue;
                    }

                    $mimes = array_merge($mimes, $mimeType->getMimeTypes($item));
                }

                $rules['mime'] = 'in:' . implode(',', $mimes);
            }

            $data = [
                'size' => $disk->size($path),
                'name' => $name,
                'mime' => $disk->mimeType($path)
            ];

            $validation = Validator::make($data, $rules);

            if ($validation->fails()) {
                throw new ValidationException($validation);
            }

            $model->file_name = $data['name'];
            $model->content_type = $data['mime'];

            return $path;
        });
    }
}
