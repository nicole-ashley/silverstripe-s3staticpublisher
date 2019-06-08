<?php

namespace NikRolls\SilverStripe_S3StaticPublisher;

use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\TextField;
use SilverStripe\ORM\DataExtension;

class SubsiteExtension extends DataExtension
{
    private static $db = [
        'S3StaticPublisherAccessKeyID' => 'Varchar(128)',
        'S3StaticPublisherSecretAccessKey' => 'Varchar(128)',
        'S3StaticPublisherRegion' => 'Varchar(64)',
        'S3StaticPublisherBucketName' => 'Varchar(64)',
        'S3StaticPublisherPathPrefix' => 'Varchar(1024)'
    ];

    public function updateCMSFields(FieldList $fields) {
        $fields->addFieldsToTab('Root.S3StaticPublisher', [
            TextField::create('S3StaticPublisherAccessKeyID', 'Access Key ID'),
            TextField::create('S3StaticPublisherSecretAccessKey', 'Secret Access Key'),
            TextField::create('S3StaticPublisherRegion', 'Region'),
            TextField::create('S3StaticPublisherBucketName', 'Bucket name'),
            TextField::create('S3StaticPublisherPathPrefix', 'Path prefix')
        ]);
    }
}
