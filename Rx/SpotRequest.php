<?php

/**
 * Created by PhpStorm.
 * User: rajan
 * Date: 17/09/15
 * Time: 5:24 PM
 */

namespace Rx;

use Aws\Ec2\Ec2Client;

/**
 * Class SpotRequest
 * @package app\models\aws
 *
 * Simple wrapper class to use AWS PHP SDK to reserve a spot instance and change public ip address dynamically on demand
 */
class SpotRequest {

    /**
     * @var array Default Configuration parameters
     * Read the comments alongside for initial setup
     * Any of these config can be overridden using by passing config array in the constructor
     */
    public $config = array(
        'region'              => 'ap-southeast-2',
        'version'             => 'latest',
        // Use credentials file in ~/.aws directory and create a `default` profile with params aws_access_key_id and aws_secret_access_key
        'profile'             => 'default',
        // Create a dev-server-rules security or replace this , otherwise this might create and exception,
        'securityGroupName'   => 'dev-server-rules',
        // hardcoded key name , replace while constructing a new object if required
        'securityKeyName'     => 'xxxxxxxxx',
        'LaunchSpecification' => array(
            // Hardcoded image id
            'ImageId'      => 'xxxxxxxx',
            'InstanceType' => 'xxxxxxx'
        ),
        'SpotPrice'           => '0.0135'

    );

    /**
     * @var bool Default false
     */
    public $dryRun = false;

    /**
     * @var Ec2Client|null
     */
    private $ec2Client = null;

    /**
     * @param array $config
     * @param Ec2Client $ec2Client
     *
     * Creates a basic EC2 Client Factory object using config parameters
     */
    function __construct($config = array(), $ec2Client = null) {
        $this->config    = array_merge($this->config, $config);
        $this->ec2Client = $ec2Client;

        if ($this->ec2Client == null) {
            $this->ec2Client = Ec2Client::factory(array(
                'region'  => $this->config['region'],
                'version' => $this->config['version'],
                'profile' => $this->config['profile']
            ));
        }
    }

    /**
     * Provides list of the spot requests.
     * @return null
     */
    public function getSpotRequests(){
        $spotRequests = $this->ec2Client->describeSpotInstanceRequests();
        return $spotRequests['SpotInstanceRequests'];
    }

    /**
     * Provides current active spot if available otherwise returns null
     * @return array|null
     * @throws \Exception
     */
    public function getActiveSpot(){

        $spots = $this->getSpotRequests();
        $activeSpot = null;

        foreach($spots as $spot){

            switch($spot['Status']['Code']){
                case 'pending-evaluation':
                case 'request-canceled-and-instance-running':
                case 'canceled-before-fulfillment':
                case 'instance-terminated-by-user':
                    continue;
                case 'fulfilled':
                    $activeSpot = $spot;
                    break;
                case 'price-too-low':
                    $this->ec2Client->cancelSpotInstanceRequests([
                        'DryRun' => $this->dryRun,
                        'SpotInstanceRequestIds' => array($spot['SpotInstanceRequestId']),
                    ]);
                    break;
                default:
                    throw new \Exception ($spot['Status']['Code'] . " spot status not handled yet.");
            }

        }

        return $activeSpot;
    }

    /**
     * Creates a new spot request using config parameters
     * @param null $price Overrides price of config if provided
     */
    public function reserveSpot($price = null) {

        $this->ec2Client->requestSpotInstances(array(
            'DryRun'              => $this->dryRun,
            // SpotPrice is mandatory
            'SpotPrice'           => $price == null ? $this->config['SpotPrice'] : $price,
            'InstanceCount'       => 1,
            'LaunchSpecification' => array(
                'ImageId'      => $this->config['LaunchSpecification']['ImageId'],
                'InstanceType' => $this->config['LaunchSpecification']['InstanceType']
            ),
            'Monitoring'          => array(
                // Enabled is mandatory field
                'Enabled' => true,
            ),
            'SecurityGroups'      => array($this->config['securityGroupName']),
            'KeyName'             => $this->config['securityKeyName']
        ));
    }

    /**
     * Disassociates and releases the currently assigned ip from elastic ip and creates and assign new elastic ip to the active instance.
     * @param null $instanceId
     *
     * @throws \Exception
     */
    public function refreshIp($instanceId = null){

        if($instanceId == null) {
            $instanceId = $this->getActiveInstanceId();
            if($instanceId == null){
                throw new \Exception ("Active instance not found to assign ip");
            }
        }

        $currentIp = $this->getIp($instanceId);
        try{
            $this->disAssociateElasticIP($currentIp);
        }
        catch(\Exception $ex){
            echo $ex->getCode()." : ".$ex->getMessage()."\n";
        }

        $newAddress = $this->ec2Client->allocateAddress();
        $newIp = $newAddress['PublicIp'];

        $this->associateElasticIP($instanceId,$newIp);

        $this->releaseElasticIp($currentIp);

    }

    /**
     * Gets public ip address of currently active spot instance
     * @param null $instanceId
     *
     * @return mixed
     * @throws \Exception
     */
    public function getIp($instanceId = null){
        if($instanceId == null) {
            $instanceId = $this->getActiveInstanceId();
            if($instanceId == null){
                throw new \Exception ("Active instance not found to assign ip");
            }
        }

        $instances = $this->ec2Client->describeInstances([
            'Filters' => [
                [
                    'Name'   => 'instance-id',
                    'Values' => [$instanceId]
                ],
            ]
        ]);

        return $instances['Reservations'][0]['Instances'][0]['PublicIpAddress'];

    }

    /**
     * @return null
     * @throws \Exception
     */
    private function getActiveInstanceId(){
        $activeSpot = $this->getActiveSpot();
        if(!count($activeSpot)){
            return null;
        }
        return $activeSpot['InstanceId'];
    }


    /**
     * Associates the ip address in to given instance
     * @param $instanceId
     * @param $ip
     */
    private function associateElasticIP($instanceId, $ip) {
        $this->ec2Client->associateAddress([
            'DryRun'             => $this->dryRun,
            'InstanceId'         => $instanceId,
            'PublicIp'           => $ip,
            'AllowReassociation' => true
        ]);
    }

    /**
     * Disassociates ip address from the attached instance
     * @param $ip
     */
    private function disAssociateElasticIP($ip) {
        $this->ec2Client->disassociateAddress([
            'DryRun'   => false,
            'PublicIp' => $ip
        ]);
    }

    /**
     * Removes the ip from elastic ip pool
     * @param $ip
     * @param bool $safeMode
     *
     * @throws \Exception
     */
    private function releaseElasticIp($ip, $safeMode = false) {

        if($safeMode){
            throw new \Exception("Safe mode release not implemented yet");
        }

        $this->ec2Client->releaseAddress([
            'DryRun'       => false,
            'AllocationId' => $this->getAllocationId($ip)
        ]);
    }

    /**
     * Gets the allocation id for given elastic ip
     * @param $ip
     *
     * @return mixed
     * @throws \Exception
     */
    private function getAllocationId($ip) {
        $addresses = $this->ec2Client->describeAddresses();

        foreach ($addresses['Addresses'] as $address) {
            if ($address['PublicIp'] == $ip) {
                return $address['AllocationId'];
            }
        }

        throw new \Exception("could not find allocation id for given ip address");
    }
}