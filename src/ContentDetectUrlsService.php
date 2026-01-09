<?php

declare(strict_types=1);

namespace SharpAPI\ContentDetectUrls;

use GuzzleHttp\Exception\GuzzleException;
use InvalidArgumentException;
use SharpAPI\Core\Client\SharpApiClient;

/**
 * @api
 */
class ContentDetectUrlsService extends SharpApiClient
{
    /**
     * Initializes a new instance of the class.
     *
     * @throws InvalidArgumentException if the API key is empty.
     */
    public function __construct()
    {
        parent::__construct(config('sharpapi-content-detect-urls.api_key'));
        $this->setApiBaseUrl(
            config(
                'sharpapi-content-detect-urls.base_url',
                'https://sharpapi.com/api/v1'
            )
        );
        $this->setApiJobStatusPollingInterval(
            (int) config(
                'sharpapi-content-detect-urls.api_job_status_polling_interval',
                5)
        );
        $this->setApiJobStatusPollingWait(
            (int) config(
                'sharpapi-content-detect-urls.api_job_status_polling_wait',
                180)
        );
        $this->setUserAgent('SharpAPILaravelContentDetectUrls/1.0.0');
    }

    /**
     * Parses the provided text for any possible URLs. Might come in handy in case of processing and validating
     * big chunks of data against URLs or if you want to detect URLs in places where they're not supposed to be.
     *
     * @param string $text The text to analyze for URLs
     * @return string The detected URLs or an error message
     *
     * @throws GuzzleException
     *
     * @api
     */
    public function detectUrls(string $text): string
    {
        $response = $this->makeRequest(
            'POST',
            '/content/detect_urls',
            ['content' => $text]
        );

        return $this->parseStatusUrl($response);
    }
}