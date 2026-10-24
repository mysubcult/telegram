<?php

namespace LiveHelperChatExtension\lhctelegram\providers;

/**
 * Text description of every file a visitor sends, stored once in lh_chat_file.caption_ocr:
 * photo → what it shows + the text on it, voice → transcript, PDF → text.
 * Reply suggestions then see earlier files of the chat as short text instead of sending the files again.
 *
 * The description comes from the REST API host of the suggestions (smarti-ai worker, mode @describe),
 * in the background (resque), so a slow description never delays the chat.
 *
 * settings/settings.ini.php: 'suggest' => array(..., 'captions' => true)
 */
#[\AllowDynamicProperties]
class TelegramFileCaption
{
    const MAX_FILE_BYTES = 10485760;
    const DESCRIBE_SUBURL = '/v1beta/models/deepseek-flash@describe:generateContent';

    public static function isEnabled()
    {
        $settings = \erLhcoreClassModule::getExtensionInstance('erLhcoreClassExtensionLhctelegram')->settings;
        return !empty($settings['suggest']['captions']) && TelegramSuggest::getSettings()['rest_api_id'] > 0;
    }

    public static function isDescribable($file)
    {
        return preg_match('#^(image/(jpeg|png|webp|gif)|audio/|application/pdf$)#', strtolower((string)$file->type)) === 1;
    }

    /**
     * Listener for file.uploadfile.file_store (widget uploads and files from Telegram/MAX webhooks)
     */
    public static function fileStored($params)
    {
        if (!isset($params['chat_file']) || !($params['chat_file'] instanceof \erLhcoreClassModelChatFile)) {
            return;
        }

        $file = $params['chat_file'];

        if ((int)$file->user_id !== 0 || (int)$file->chat_id <= 0 || !self::isDescribable($file) || !self::isEnabled()) {
            return;
        }

        self::enqueue($file->id);
    }

    public static function enqueue($fileId)
    {
        $resque = \erLhcoreClassModule::getExtensionInstance('erLhcoreClassExtensionLhcphpresque');
        if (!is_object($resque)) {
            return false;
        }

        $resque->enqueue(TelegramSuggest::QUEUE, '\\' . self::class, array('file_id' => (int)$fileId));
        return true;
    }

    // Resque job
    public function perform()
    {
        $db = \ezcDbInstance::get();
        $db->reconnect();

        $file = \erLhcoreClassModelChatFile::fetch((int)$this->args['file_id']);

        if (!($file instanceof \erLhcoreClassModelChatFile) || trim((string)$file->caption_ocr) !== '') {
            return;
        }

        $caption = self::describe($file);

        if ($caption !== '') {
            $file->caption_ocr = $caption;
            $file->updateThis(['update' => ['caption_ocr']]);
        }
    }

    // '' when the file can not be described (too big, unsupported, the worker failed)
    public static function describe(\erLhcoreClassModelChatFile $file)
    {
        if (!self::isDescribable($file) || !is_file($file->file_path_server) || filesize($file->file_path_server) > self::MAX_FILE_BYTES) {
            return '';
        }

        $restAPI = \erLhcoreClassModelGenericBotRestAPI::fetch(TelegramSuggest::getSettings()['rest_api_id']);
        if (!($restAPI instanceof \erLhcoreClassModelGenericBotRestAPI) || empty($restAPI->configuration_array['host'])) {
            return '';
        }

        // Same worker and key as the suggestions
        $headers = array('Content-Type: application/json', 'User-Agent: LHC file caption');
        foreach ((array)($restAPI->configuration_array['parameters'][0]['header'] ?? array()) as $header) {
            if (isset($header['key'], $header['value']) && strtolower($header['key']) === 'x-goog-api-key') {
                $headers[] = 'x-goog-api-key: ' . $header['value'];
            }
        }

        $body = array('contents' => array(array('role' => 'user', 'parts' => array(array('inlineData' => array(
            'mimeType' => strtolower((string)$file->type),
            'data' => base64_encode(file_get_contents($file->file_path_server))
        ))))));

        $ch = curl_init(rtrim($restAPI->configuration_array['host'], '/') . self::DESCRIBE_SUBURL);
        curl_setopt_array($ch, array(
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => json_encode($body)
        ));
        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            \erLhcoreClassLog::write('File caption failed [' . $httpCode . '] ' . mb_substr((string)$response, 0, 300),
                \ezcLog::SUCCESS_AUDIT,
                array(
                    'source' => 'lhc',
                    'category' => 'telegram_exception',
                    'line' => __LINE__,
                    'file' => __FILE__,
                    'object_id' => $file->chat_id
                )
            );
            return '';
        }

        $data = json_decode((string)$response, true);
        return trim((string)($data['candidates'][0]['content']['parts'][0]['text'] ?? ''));
    }
}
