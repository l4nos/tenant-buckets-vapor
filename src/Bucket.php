<?php

namespace Lanos\TenantBuckets;

use Aws\Credentials\CredentialProvider;

use Aws\Credentials\Credentials;
use Aws\Exception\AwsException;
use Aws\S3\S3Client;
use Illuminate\Support\Facades\Log;
use Lanos\TenantBuckets\Events\CreatedBucket;
use Lanos\TenantBuckets\Events\CreatingBucket;
use Lanos\TenantBuckets\Events\DeletedBucket;
use Lanos\TenantBuckets\Events\DeletingBucket;
use Stancl\Tenancy\Contracts\TenantWithDatabase;

class Bucket
{
    /**
     * @access public
     * @var Stancl\Tenancy\Contracts\TenantWithDatabase Tenant
     * @var AWS Credentials Object
     * @var AWS/Minio Endpoint
     * @var AWS/Minio Region
     * @var Use Path style endpoint (used for minio)
     * @access protected
     * @var string|null Name of the Created Bucket
     * @var Aws\Exception\AwsException|null Exception Error Bag
     */
    public $tenant;
    public $credentials;
    public $endpoint;
    public $region;
    public string $version = "2006-03-01";
    public bool $pathStyle = false;
    protected string|null $createdBucketName;
    protected AwsException|null $e;

    /**
     * Setup the Bucket Object
     *
     * @access public
     * @param Stancl\Tenancy\Contracts\TenantWithDatabase $tenant Current Teanant
     * @param Aws\Credentials\Credentials $credentials Aws Credentials Object
     * @param string $endpoint Aws/Minio Endpoint
     * @param bool $pathStyle Use Path Style Endpoint (set `true` for minio, default: false)
     * @return void
     */
    public function __construct(
        TenantWithDatabase $tenant,
        ?Credentials $credentials = null,
        ?string $region = null,
        ?string $endpoint = null,
        ?bool $pathStyle = null
    ) {
        $providrr = CredentialProvider::env();

        $this->tenant = $tenant;
        $this->credentials = $credentials ?? new Credentials(config('services.aws.key'), config('services.aws.secret'));
        $this->region = $region ?? config('filesystems.disks.s3.region');
        $this->endpoint = $endpoint ?? config('filesystems.disks.s3.endpoint');
        $pathStyle = $pathStyle ?? config('filesystems.disks.s3.use_path_style_endpoint');
        $this->pathStyle = $pathStyle ?? $this->pathStyle;
    }

    /**
     * Create Tenant Specific Bucket
     *
     * @access public
     * @return self $this
     */
    public function createTenantBucket(): self
    {
        $bucketName = config('tenancy.filesystem.suffix_base').$this->tenant->getTenantKey();

        return $this->createBucket($bucketName);
    }

    /**
     * Delete Tenant Specific Bucket
     *
     * @access public
     * @return self $this
     */
    public function deleteTenantBucket(): self
    {
        $bucketName = $this->tenant->tenant_bucket;

        return $bucketName ? $this->deleteBucket($bucketName, $this->credentials) : false;
    }

    /**
     * Create a New Bucket
     *
     * @param string $name Name of the S3 Bucket
     * @access public
     * @return self $this
     */
    public function createBucket(string $name): self
    {
        event(new CreatingBucket($this->tenant));

        $params = [
            "credentials" => CredentialProvider::env(),
            "endpoint" => $this->endpoint,
            "region" => $this->region,
            "version" => $this->version,
            "use_path_style_endpoint" => $this->pathStyle,
        ];

        $client = new S3Client($params);

        try {
            $exec = $client->createBucket([
                'Bucket' => $name,
            ]);
            $this->createdBucketName = $name;

            // REMOVE PUBLIC ACCESS BLOCK
            $client->putPublicAccessBlock([
                'Bucket' => $name,
                "PublicAccessBlockConfiguration" => [
                    "BlockPublicAcls" => false,
                    "IgnorePublicAcls" => false,
                    "BlockPublicPolicy" => false,
                    "RestrictPublicBuckets" => false,
                ],
            ]);

            // ENABLE ACLS

            $client->putBucketOwnershipControls([
                'Bucket' => $name,
                'OwnershipControls' => [
                    "Rules" => [
                        ["ObjectOwnership" => "BucketOwnerPreferred"],
                    ],
                ],
            ]);

            // SET CORS FOR PRE-SIGNED UPLOAD ACCESS
            $client->putBucketCors([
                'Bucket' => $name,
                'CORSConfiguration' => [
                    'CORSRules' => [
                        [
                            'AllowedHeaders' => ['*'],
                            'AllowedMethods' => ['POST', 'GET', 'PUT', 'DELETE'],
                            'AllowedOrigins' => ['*'],
                            'ExposeHeaders' => [],
                            'MaxAgeSeconds' => 3000,
                        ],
                    ],
                ],
            ]);

            // Update Tenant
            $this->tenant->tenant_bucket = $name;
            $this->tenant->save();
        } catch (AwsException $e) {
            $this->e = $e;
            Log::error($this->getErrorMessage());
        }

        event(new CreatedBucket($this->tenant));

        return $this;
    }

    /**
     * Create a New Bucket
     *
     * @param string $name Name of the S3 Bucket
     * @param Aws\Credentials\Credentials $credentials AWS Credentials Object
     * @access public
     * @return self $this
     */
    public function deleteBucket(string $name, Credentials $credentials): self
    {
        event(new DeletingBucket($this->tenant));

        $params = [
            "credentials" => $credentials,
            "endpoint" => $this->endpoint,
            "region" => $this->region,
            "version" => $this->version,
            "use_path_style_endpoint" => $this->pathStyle,
        ];

        $client = new S3Client($params);

        try {
            $exec = $client->deleteBucket([
                'Bucket' => $name,
            ]);
        } catch (AwsException $e) {
            $this->e = $e;
            Log::error($this->getErrorMessage());
        }

        event(new DeletedBucket($this->tenant));

        return $this;
    }

    /**
     * Get Created Bucket Name
     *
     * @return string
     */
    public function getBucketName(): string|null
    {
        return $this->createdBucketName;
    }

    /**
     * Get Error Messsge
     *
     * @return string|null
     */
    public function getErrorMessage(): string|null
    {
        return ($this->e) ?
        "Error: " . $this->e->getAwsErrorMessage() :
        null;
    }

    /**
     * Get Error Bag
     *
     * @return AwsException|null
     */
    public function getErrorBag(): AwsException|null
    {
        return $this->e ? $this->e : null;
    }
}
