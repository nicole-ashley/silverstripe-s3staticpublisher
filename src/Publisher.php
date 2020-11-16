<?php

namespace NikRolls\SilverStripe_S3StaticPublisher;

use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\Controller;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ErrorPage\ErrorPage;
use SilverStripe\StaticPublishQueue\Publisher\FilesystemPublisher;

class Publisher extends FilesystemPublisher
{
    private static $trailing_slashes = false;

    protected $fileExtension = 'html';

    public function publishURL($url, $forcePublish = false)
    {
        if (!$url) {
            user_error('Bad url:' . var_export($url, true), E_USER_WARNING);
            return;
        }

        if (strpos($url, '?')) {
            $url = substr($url, 0, strpos($url, '?'));
        }

        $success = false;
        $response = $this->generatePageResponse($url);
        $statusCode = $response->getStatusCode();

        if ($statusCode < 300) {
            $success = $this->publishPage($response, $url);
        } elseif ($statusCode === 404) {
            $model = SiteTree::get_by_link($url);
            if ($model && $model instanceof ErrorPage && $model->isPublished()) {
                $success = $this->publishPage($response, $url);
            } else {
                // This job completed as expected, even though we didn't publish it
                $success = true;
            }
        } elseif ($statusCode < 400) {
            $success = $this->publishRedirect($response, $url);
        }

        return [
            'published' => $success,
            'success' => $success,
            'responsecode' => $statusCode,
            'url' => $url,
        ];
    }

    protected function publishRedirect($response, $url)
    {
        $object = ['WebsiteRedirectLocation' => $response->getHeader('Location')];
        if ($response->getHeader('Expires')) {
            $object['Expires'] = $response->getHeader('Expires');
        }
        if ($this->needsSlashRedirect($url)) {
            $this->putObject($object, "$url/index.html");
        }
        return $this->putObject($object, $url);
    }

    protected function publishPage($response, $url)
    {
        $body = $response->getBody();
        $object = [
            'Body' => $body,
            'ContentLength' => mb_strlen($body),
            'ContentType' => $response->getHeader('Content-Type')
        ];
        if ($response->getHeader('Expires')) {
            $object['Expires'] = $response->getHeader('Expires');
        }
        if ($this->needsSlashRedirect($url)) {
            $response->addHeader('Location', parse_url($url)['path']);
            $this->publishRedirect($response, "$url/index.html");
        }
        return $this->putObject($object, $url);
    }

    public function purgeURL($url)
    {
        if (!$url) {
            user_error('Bad url:' . var_export($url, true), E_USER_WARNING);
            return;
        }

        if (strpos($url, '?')) {
            $url = substr($url, 0, strpos($url, '?'));
        }

        try {
            $this->deleteObject(Controller::join_links($url, 'index.html'));
        } catch (S3Exception $e) {
            // Gracefully continue
        }
        return [
            'success' => $this->deleteObject($url),
            'url' => $url,
            'path' => false,
        ];
    }

    public function getPublishedURLs($dir = null, &$result = [])
    {
        // TODO: We need to add some kind of "authority to control an entire bucket" option before implementing
        // this method, because it's used by StaticCacheFullBuildJob to clean up the filesystem after a full build.
        // If we don't know for sure that we have authority to control the bucket, we shouldn't allow uncontrolled
        // files to be deleted.
        return [];
    }


    private function needsSlashRedirect($url)
    {
        $urlParts = parse_url($url);
        return !static::config()->get('trailing_slashes') &&
            !empty($urlParts['path']) &&
            substr($urlParts['path'], -11) !== '/index.html';
    }

    private function putObject($object, $url)
    {
        $configuration = $this->configureObject($object, $url);
        /** @var S3Client $client */
        $client = $configuration['client'];
        $result = $client->putObject($configuration['object']);
        return $result->hasKey('VersionId');
    }

    private function deleteObject($url)
    {
        $configuration = $this->configureObject([], $url);
        /** @var S3Client $client */
        $client = $configuration['client'];
        $result = $client->deleteObject($configuration['object']);
        return $result->hasKey('VersionId');
    }

    private function configureObject($object, $url)
    {
        $urlParts = parse_url($url);
        $path = isset($urlParts['path']) ? trim($urlParts['path'], '/') : '';
        $configuration = isset($urlParts['host']) ?
            $this->getConfigurationForDomain($urlParts['host']) :
            $this->getDefaultConfiguration();
        return [
            'client' => $configuration['client'],
            'object' => array_merge(
                [
                    'Bucket' => $configuration['bucket'],
                    'Key' => $this->generateBucketPath($configuration['prefix'], $path),
                ],
                $object
            )
        ];
    }

    private function getConfigurationForDomain($domain)
    {
        if (
            class_exists('\SilverStripe\Subsites\Model\Subsite') &&
            static::config()->get('domain_based_caching')
        ) {
            return $this->getConfigurationForSubsite($domain);
        } else {
            return $this->getDefaultConfiguration();
        }
    }

    private function getConfigurationForSubsite($domain)
    {
        $subsiteID = \SilverStripe\Subsites\Model\Subsite::getSubsiteIDForDomain($domain);
        $subsite = \SilverStripe\Subsites\Model\Subsite::get()->byID($subsiteID);
        return $this->generateS3ConfigurationForSubsite($subsite);
    }

    private function getDefaultConfiguration()
    {
        return $this->generateDefaultS3Configuration();
    }

    private function generateS3ConfigurationForSubsite(\SilverStripe\Subsites\Model\Subsite $subsite)
    {
        $client = $this->createS3Client(
            $this->getBacktickValueFromEnvironment($subsite->S3StaticPublisherAccessKeyID),
            $this->getBacktickValueFromEnvironment($subsite->S3StaticPublisherSecretAccessKey),
            $this->getBacktickValueFromEnvironment($subsite->S3StaticPublisherRegion),
        );
        return [
            'client' => $client,
            'bucket' => $this->getBacktickValueFromEnvironment($subsite->S3StaticPublisherBucketName),
            'prefix' => $this->getBacktickValueFromEnvironment($subsite->S3StaticPublisherPathPrefix),
        ];
    }

    private function generateDefaultS3Configuration()
    {
        $config = static::config();
        $client = $this->createS3Client(
            $config->get('access_key_id'),
            $config->get('secret_access_key'),
            $config->get('region')
        );
        return [
            'client' => $client,
            'bucket' => $config->get('bucket'),
            'prefix' => $config->get('prefix')
        ];
    }

    private function getBacktickValueFromEnvironment($value)
    {
        if (is_string($value) && preg_match('"^`(.*)`$"', trim($value), $matches)) {
            $value = Environment::getEnv($matches[1]);
        }

        return $value;
    }

    private function createS3Client($accessKeyId, $secretAccessKey, $region)
    {
        return Injector::inst()->create(
            'Aws\S3\S3Client',
            [
                'credentials' => Injector::inst()->create(
                    'Aws\Credentials\Credentials',
                    $accessKeyId,
                    $secretAccessKey
                ),
                'region' => $region,
                'version' => '2006-03-01'
            ]
        );
    }

    private function generateBucketPath($prefix, $path)
    {
        $pathParts = pathinfo($path);
        $bucketPath = Controller::join_links($prefix, $path);
        if (
            !isset($pathParts['extension']) &&
            (static::config()->get('trailing_slashes') || !trim($path, '/'))
        ) {
            $bucketPath = Controller::join_links($bucketPath, 'index.html');
        }
        return trim($bucketPath, '/');
    }
}
