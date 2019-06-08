<?php

namespace NikRolls\SilverStripe_S3StaticPublisher;

use Aws\S3\S3Client;
use SilverStripe\Control\Controller;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\StaticPublishQueue\Publisher\FilesystemPublisher;

class Publisher extends FilesystemPublisher
{
    protected $fileExtension = 'html';

    public function publishURL($url, $forcePublish = false)
    {
        if (!$url) {
            user_error("Bad url:" . var_export($url, true), E_USER_WARNING);
            return;
        }

        $success = false;
        $response = $this->generatePageResponse($url);
        $statusCode = $response->getStatusCode();

        if ($statusCode < 300 || $statusCode === 404) {
            // publish success response
            $success = $this->publishPage($response, $url);
        } elseif ($statusCode < 400) {
            // publish redirect response
            $success = $this->publishRedirect($response, $url);
        }
        return [
            'published' => $success,
            'success' => $success,
            'responsecode' => $statusCode,
            'url' => $url,
        ];
    }

    protected function publishRedirect($response, $url) {
        $object = ['WebsiteRedirectLocation' => $response->getHeader('Location')];
        if ($response->getHeader('Expires')) {
            $object['Expires'] = $response->getHeader('Expires');
        }
        return $this->putObject($object, $url);
    }

    protected function publishPage($response, $url) {
        $body = $response->getBody();
        $object = [
            'Body' => $body,
            'ContentLength' => mb_strlen($body),
            'ContentType' => $response->getHeader('Content-Type')
        ];
        if ($response->getHeader('Expires')) {
            $object['Expires'] = $response->getHeader('Expires');
        }
        return $this->putObject($object, $url);
    }

    public function purgeURL($url) {
        return [
            'success' => $this->deleteObject($url),
            'url' => $url,
            'path' => false,
        ];
    }

    private function putObject($object, $url) {
        $configuration = $this->configureObject($object, $url);
        /** @var S3Client $client */
        $client = $configuration['client'];
        $result = $client->putObject($configuration['object']);
        return $result->hasKey('VersionId');
    }

    private function deleteObject($url) {
        $configuration = $this->configureObject([], $url);
        /** @var S3Client $client */
        $client = $configuration['client'];
        $result = $client->deleteObject($configuration['object']);
        return $result->hasKey('VersionId');
    }

    private function configureObject($object, $url) {
        $urlParts = parse_url($url);
        $configuration = isset($urlParts['host']) ?
            $this->getConfigurationForDomain($urlParts['host']) :
            $this->getDefaultConfiguration();
        return [
            'client' => $configuration['client'],
            'object' => array_merge([
                'Bucket' => $configuration['bucket'],
                'Key' => $this->generateBucketPath($configuration['prefix'], $urlParts['path']),
            ], $object)
        ];
    }

    private function getConfigurationForDomain($domain) {
        if (
            class_exists('\SilverStripe\Subsites\Model\Subsite') &&
            self::config()->get('domain_based_caching')
        ) {
            return $this->getConfigurationForSubsite($domain);
        } else {
            return $this->getDefaultConfiguration();
        }
    }

    private function getConfigurationForSubsite($domain) {
        $subsiteID = \SilverStripe\Subsites\Model\Subsite::getSubsiteIDForDomain($domain);
        $subsite = \SilverStripe\Subsites\Model\Subsite::get()->byID($subsiteID);
        return $this->generateS3ConfigurationForSubsite($subsite);
    }

    private function getDefaultConfiguration() {
        return $this->generateDefaultS3Configuration();
    }

    private function generateS3ConfigurationForSubsite($subsite) {
        $client = $this->createS3Client(
            $subsite->S3StaticPublisherAccessKeyID,
            $subsite->S3StaticPublisherSecretAccessKey,
            $subsite->S3StaticPublisherRegion
        );
        return [
            'client' => $client,
            'bucket' => $subsite->S3StaticPublisherBucketName,
            'prefix' => $subsite->S3StaticPublisherPathPrefix
        ];
    }

    private function generateDefaultS3Configuration() {
        $config = self::config();
        $client = $this->createS3Client(
            $config->get('access_key_id'), $config->get('secret_access_key'), $config->get('region')
        );
        return [
            'client' => $client,
            'bucket' => $config->get('bucket'),
            'prefix' => $config->get('prefix')
        ];
    }

    private function createS3Client($accessKeyId, $secretAccessKey, $region) {
        return Injector::inst()->create('Aws\S3\S3Client', [
            'credentials' => Injector::inst()->create('Aws\Credentials\Credentials', $accessKeyId, $secretAccessKey),
            'region' => $region,
            'version' => '2006-03-01'
        ]);
    }

    private function generateBucketPath($prefix, $path) {
        $pathParts = pathinfo($path);
        $bucketPath = Controller::join_links($prefix, $path);
        if (!isset($pathParts['extension'])) {
            $bucketPath = Controller::join_links($bucketPath, 'index.html');
        }
        return trim($bucketPath, '/');
    }
}
