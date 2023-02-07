<?php
/**
 * Events Publisher class
 * This class uses to publish General Events to Instance based on Redis Databse
 * 
 * @author David A. <software@duktig.dev>
 * @license see License.md
 * @version 1.1.0
 */  

namespace System\Events;

# Use of Redis configuration
use System\Config;
use System\Logger;
use \Redis;
use \Throwable;

/**
 * Class Event
 */
class Publisher {

    /**
     * General events Redis configuration
     * 
     * @static
     * @access private
     * @var array
     */
    private static $eventsRedisConfig;

    /**
     * Events Redis Connection object
     * 
     * @static
     * @access private
     * @var object
     */
    private static $eventsRedis;

    /**
     * General Events Redis Configuration name 
     * 
     * @static
     * @access private
     * @var string
     */
    private static $eventsRedisConfigName = 'GeneralEventsRedis';

    /**
     * How many times to try reconnect
     * 
     * @static
     * @access private 
     * @var int
     */
    private static $connectionAttempts = 10;

    /**
     * Channel to publish
     * 
     * @static
     * @access private
     * @var string
     */
    private static $channelToPublish = 'main';

    /**
     * Service name
     * 
     * @static
     * @access private
     * @var string
     */
    private static $serviceName = 'Unknown';

    /**
     * Connect to Redis instance
     * 
     * @access private
     * @return bool
     */
    private static function connect() : bool {

        static::$serviceName = Config::get()['Microservice'];

        ini_set("default_socket_timeout", '-1');

        $step = 1;
        $connected = false;

        try {

            # Initialize General Events Configuration and connection for Redis
            static::$eventsRedisConfig = Config::get()['Redis'][static::$eventsRedisConfigName];

            if (!static::$eventsRedisConfig) {
                throw new \Exception('Cannot find redis configuration by '.static::$eventsRedisConfigName);
            }

            static::$eventsRedis = new Redis();

            $step = 1;
            $connected = false;

            while($connected == false) {
        
                try {
                    
                    static::$eventsRedis->connect(static::$eventsRedisConfig['host'], static::$eventsRedisConfig['port'], 0);
                    Logger::log('Connected to Redis ' . static::$eventsRedisConfigName . ' ('.$step.' attempts)', Logger::INFO, __FILE__, __LINE__);
                    $connected = true;
                    
                } catch(\Throwable $e) {
                    Logger::Log($e->getMessage(), Logger::ERROR, $e->getFile(), $e->getLine());
                    Logger::log('Retrying to connect Redis ' . static::$eventsRedisConfigName . '('.$step.' attempts)', Logger::INFO, $e->getFile(), $e->getLine());
                    $step++;
                    sleep(1);
                }

                if($step >= static::$connectionAttempts) {
                    Logger::Log('Unable to Connect Redis '.static::$eventsRedisConfigName. ' after '.$step.' attempts', Logger::ERROR, __FILE__, __LINE__);
                    return false;
                }
            }            

            if (static::$eventsRedisConfig['password'] != '') {
                static::$eventsRedis->auth(static::$eventsRedisConfig['password']);
            }

            static::$eventsRedis->select(static::$eventsRedisConfig['database']);
            
            return true;

        } catch(\Throwable $e) {
            Logger::Log($e->getMessage(), Logger::ERROR, $e->getFile(), $e->getLine());
            return false;
        }

        return false;

    }

    /**
     * Ping the Redis connection
     * Re-connect if not connected
     * 
     * @access private
     * @return bool
     */
    private static function pingConnection() : bool {

        if(!static::$eventsRedis) {
            return static::connect();
        }

        if(!static::$eventsRedis->ping()) {
            return static::connect();
        }

        return true;

    }

    /**
     * Publish to Redis Events instance
     * 
     * @access public
     * @param string $event
     * @param array $data
     * @param string | null $channelToPublish
     * @param string | null $eventsRedisConfigName
     * @return bool
     */
    public static function publish(string $event, array $data, ?string $channelToPublish = null, ?string $eventsRedisConfigName = null) : bool {
        
        if(!is_null($eventsRedisConfigName)) {
            static::$eventsRedisConfigName = $eventsRedisConfigName;
        }

        if(!is_null($channelToPublish)) {
            static::$channelToPublish = $channelToPublish;
        }

        if(!static::pingConnection()) {
            return false;
        }

        $publishingData = json_encode([
            'event' => $event,
            'service' => static::$serviceName,
            'published_time' => date('Y-m-d H:i:s'),
            'data' => $data
        ]);
        
        # Publish an event to channel
        try {
            static::$eventsRedis->publish(static::$channelToPublish, $publishingData);
        } catch(Throwable $e) {
            Logger::log($e->getMessage(), Logger::ERROR, $e->getFile(), $e->getLine());
            return false;
        }

        return true;

    }

}