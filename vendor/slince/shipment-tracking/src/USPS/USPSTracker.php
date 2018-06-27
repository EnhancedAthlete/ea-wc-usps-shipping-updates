<?php
/**
 * Slince shipment tracker library
 * @author Tao <taosikai@yeah.net>
 */
namespace Slince\ShipmentTracking\USPS;

use Carbon\Carbon;
use Psr\Http\Message\ResponseInterface;
use Slince\ShipmentTracking\Foundation\Exception\TrackException;
use Slince\ShipmentTracking\Foundation\HttpAwareTracker;
use GuzzleHttp\Client as HttpClient;
use Slince\ShipmentTracking\Foundation\Shipment;
use Slince\ShipmentTracking\Utility;

class USPSTracker extends HttpAwareTracker
{
    const TRACKING_ENDPOINT  = 'http://production.shippingapis.com/ShippingAPI.dll';

    /**
     * @var string
     */
    protected $userId;

    /**
     * You can get your userID from the following url
     * {@link https://www.usps.com/business/web-tools-apis/welcome.htm}
     */
    public function __construct($userId, HttpClient $httpClient = null)
    {
        $this->userId = $userId;
        $httpClient && $this->setHttpClient($httpClient);
    }

    /**
     * @return string
     */
    public function getUserId()
    {
        return $this->userId;
    }

    /**
     * @param string $userId
     * @return USPSTracker
     */
    public function setUserId($userId)
    {
        $this->userId = $userId;
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function track($trackingNumber)
    {
        try {
            $response = $this->request([
                'query' => [
                    'API' => 'TrackV2',
                    'XML' => static::buildXml($this->userId, $trackingNumber)
                ]
            ]);
            $array = Utility::parseXml($response->getBody());
        } catch (\Exception $exception) {
            throw new TrackException($exception->getMessage());
        }
        if (!isset($array['TrackInfo'])) {
            throw new TrackException($array['Description']);
        }
        if (isset($array['TrackInfo']['Error'])) {
            throw new TrackException($array['TrackInfo']['Error']['Description']);
        }
        if (!isset($array['TrackInfo']['TrackSummary']) && !isset($array['TrackInfo']['TrackDetail'])) {
            throw new TrackException('Cannot find any events');
        }

        error_log( $response->getBody() );

	    $shipment = static::buildShipment($array);
        return $shipment;
    }

	/**
	 * @param sting[] $trackingNumbers
	 *
	 * @return Shipment[] // [ tracking_number : Shipment ]
	 */
	public function trackMulti($trackingNumbers)
	{

		if (count($trackingNumbers)==1)
			return array( $trackingNumbers[0] => $this->track($trackingNumbers[0]) );

		try {
			$response = $this->request([
				'query' => [
					'API' => 'TrackV2',
					'XML' => static::buildXmlMulti($this->userId, $trackingNumbers)
				]
			]);
			$array = Utility::parseXml($response->getBody());
		} catch (\Exception $exception) {
			throw new TrackException($exception->getMessage());
		}

		if (!isset($array['TrackInfo'])) {
			throw new TrackException($array['Description']);
		}
		if (isset($array['TrackInfo']['Error'])) {
			throw new TrackException($array['TrackInfo']['Error']['Description']);
		}

		/** @var Shipment[] $shipments */
		$shipments = array();

		foreach($array['TrackInfo'] as $trackInfo) {
			$tracking_number = $trackInfo['@attributes']['ID'];
			$shipments[ $tracking_number ] = static::buildShipment( array( 'TrackInfo' => $trackInfo ) );
		}

		return $shipments;
	}

	/**
     * @param array $options
     * @return ResponseInterface
     * @codeCoverageIgnore
     */
    protected function request($options)
    {
        return $this->getHttpClient()->get(static::TRACKING_ENDPOINT, $options);
    }

	/**
	 * @param string $userId
	 * @param string $trackID
	 * @return string
	 */
	protected static function buildXml($userId, $trackID)
	{
		$xmlTemplate = <<<XML
<?xml version="1.0" encoding="UTF-8" ?>
<TrackFieldRequest USERID="%s">
  <TrackID ID="%s"></TrackID>
</TrackFieldRequest>
XML;
		return sprintf($xmlTemplate, $userId, $trackID);
	}

	/**
	 * @param string $userId
	 * @param string[] $trackID
	 * @return string
	 */
	protected static function buildXmlMulti($userId, $trackIDs)
	{
		$xmlRequest  = '<?xml version="1.0" encoding="UTF-8" ?>';
		$xmlRequest .= '<TrackFieldRequest USERID="'.$userId.'">';

		foreach($trackIDs as $trackID)
			$xmlRequest .= '<TrackID ID="' . trim( $trackID ) . '"></TrackID>';

		$xmlRequest .= '</TrackFieldRequest>';

		return $xmlRequest;
	}

	/**
     * @param array $array
     * @return Shipment
     */
    protected static function buildShipment($array)
    {

//        error_log("\n\n");
//    	error_log( json_encode( $array) );
//	    error_log("\n\n");

        if (!empty($array['TrackInfo']['TrackDetail'])) {
            $trackDetails = is_numeric(key($array['TrackInfo']['TrackDetail']))
                ? $array['TrackInfo']['TrackDetail']
                : [$array['TrackInfo']['TrackDetail']];
        } else {
            $trackDetails =  [];
        }
        //The track summary is also valid
        if (isset($array['TrackInfo']['TrackSummary'])) {
            array_unshift($trackDetails, $array['TrackInfo']['TrackSummary']);
        }
        $events = array_map(function($eventData){
            $time = empty($eventData['EventTime']) ? '' : $eventData['EventTime'];
            $day = empty($eventData['EventDate']) ? '' : $eventData['EventDate'];
            $country = empty($eventData['EventCountry']) ? '' : $eventData['EventCountry'];
            $state = empty($eventData['EventState']) ? '' : $eventData['EventState'];
            $city = empty($eventData['EventCity']) ? '' : $eventData['EventCity'];
            $zipCode = empty($eventData['EventZIPCode']) ? '' : $eventData['EventZIPCode'];
            return ShipmentEvent::fromArray([
                'day' => $day,
                'city' => $city,
                'state' => $state,
                'country' => $country,
                'date' => Carbon::parse("{$day} {$time}"),
                'location' => new Location($country, $state, $city),
                'description' => $eventData['Event'],
                'zipCode' => $zipCode
            ]);
        }, array_reverse($trackDetails));

//        error_log( json_encode( $events ) );
//
//        error_log( "\n\n");

        $shipment = new Shipment($events);
        if (isset($array['TrackInfo']['TrackSummary']['DeliveryAttributeCode'])) {

	        $deliveredAtributeCodes = array( '01', '02', '03', '04', '05', '06', '08', '09', '10', '11', '17', '19', '23' );
	        
        	$is_delivered = in_array($array['TrackInfo']['TrackSummary']['DeliveryAttributeCode'], $deliveredAtributeCodes);

            $shipment->setIsDelivered( $is_delivered );

            if( !in_array( $array['TrackInfo']['TrackSummary']['DeliveryAttributeCode'], $deliveredAtributeCodes ) )
                error_log( $array['TrackInfo']['TrackSummary']['DeliveryAttributeCode'] . ' : ' . $array['TrackInfo']['TrackSummary']['Event'] );

	        $shipment->setStatus($array['TrackInfo']['TrackSummary']['Event']);

        } elseif (!empty($array['TrackInfo']['TrackSummary']['Event']) && $array['TrackInfo']['TrackSummary']['Event'] == 'Delivered') {
        	// Overseas deliveries don't have a DeliveryAttributeCode, just a status of "Delivered"
	        $shipment->setIsDelivered(true);
	        $shipment->setStatus($array['TrackInfo']['TrackSummary']['Event']);

        } elseif (!empty($array['TrackInfo']['TrackSummary']['Event'])) {
	        $shipment->setStatus($array['TrackInfo']['TrackSummary']['Event']);
        } elseif (!empty($array['TrackInfo']['TrackDetail'])) {
	        $shipment->setStatus( $array['TrackInfo']['TrackDetail'][0]['Event'] );
        }

        if ($shipment->isDelivered() && $firstEvent = reset($events)) {
            $shipment->setDeliveredAt($firstEvent->getDate());
        }

        return $shipment;
    }
}