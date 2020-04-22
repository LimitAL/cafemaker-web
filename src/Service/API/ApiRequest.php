<?php

namespace App\Service\API;

use App\Common\Service\Redis\RedisTracking;
use App\Common\ServicesThirdParty\Discord\Discord;
use App\Common\ServicesThirdParty\Google\GoogleAnalytics;
use App\Common\Utils\Language;
use App\Controller\CompanionController;
use App\Controller\MappyController;
use App\Controller\MarketController;
use App\Controller\MarketPrivateController;
use App\Controller\TooltipsController;
use App\Controller\LodestoneCharacterController;
use App\Controller\LodestoneController;
use App\Controller\LodestoneFreeCompanyController;
use App\Controller\LodestonePvPTeamController;
use App\Controller\SearchController;
use App\Controller\XivGameContentController;
use App\Common\Entity\User;
use App\Exception\ApiAppBannedException;
use App\Exception\ApiRateLimitException;
use App\Common\Service\Redis\Redis;
use App\Common\User\Users;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpFoundation\Request;

/**
 * todo - exceptions in this class should be unique
 */
class ApiRequest
{
    const KEY_FIELD             = 'private_key';
    const MAX_RATE_LIMIT_KEY    = 30;
    const MAX_RATE_LIMIT_GLOBAL = 12;
    
    /**
     * List of controllers that require a API Key
     */
    const API_CONTROLLERS = [
        MarketController::class,
        MarketPrivateController::class,
        CompanionController::class,
        MappyController::class,
        TooltipsController::class,
        LodestoneCharacterController::class,
        LodestoneController::class,
        LodestoneFreeCompanyController::class,
        LodestonePvPTeamController::class,
        SearchController::class,
        XivGameContentController::class
    ];

    /**
     * Static ID for requests, will either be a hashed ip or
     * a developer key (whatever is used to rate limit)
     *
     * static   = never changes for same client
     * dynamic  = static + timestamp
     * unique   = random uuid each time
     */
    public static $idStatic;
    public static $idDynamic;
    public static $idUnique;

    /**
     * @var Users
     */
    private $users;
    /**
     * The current active user for this request
     * @var User
     */
    private $user;
    /**
     * @var Request
     */
    private $request;
    /**
     * @var string
     */
    private $apikey;

    public function __construct(Users $users)
    {
        $this->users = $users;
    }

    /**
     * Handle an incoming API request, this will:
     * - Check the controller being requested
     * - Checks the API key exists
     * - Checks the user for the API key is legit
     * - Checks the user is not banned, has access, access hasn't been suspended and checks endpoint access
     * - Checks rate limit (and increments it)
     * - Send any analytics data
     * - Registers the users permissions
     */
    public function handle(Request $request)
    {
        $iphash = md5($request->getClientIp());
        
        // stop spam from this user
        if ($iphash == '6eb1b1332a4d9816c2c236fc06f9b7f9') {
            $goto = long2ip(rand(0, "4294967295"));
            header("Location: http://{$goto}/");
            exit;
        }
        
        $this->request = $request;
        $this->apikey  = trim($this->request->get(self::KEY_FIELD));

        // set request ids
        $this->setApiRequestIds();

        // if this request is not against an API controller, we don't need to do anything.
        if ($this->isApiController() === false) {
            return;
        }
        
        // if no key, handle per ip
        if ($this->hasApiKey() === false) {
            $this->checkUserRateLimit();
            return;
        }
        
        // Track users key
        RedisTracking::increment('API_KEY_USAGE_'. $this->apikey);
        RedisTracking::increment('API_ENDPOINT_'. $this->getRequestEndpoint());

        /** @var User $user */
        $this->user = $this->users->getUserByApiKey($this->apikey);
        $this->setApiPermissions();

        // checks
        $this->checkUserIsNotBanned();

        // rate limit
        $this->checkDeveloperRateLimit();

        // send any developer Google Analytics data
        $this->sendDeveloperAnalyticData();

        // log daily limits
        $this->recordDailyLimit();
        
        file_put_contents(
            __DIR__.'/../../../api_logs.txt',
            sprintf(
                "[%s] %s (%s %s) - %s\n",
                date('Y-m-d H:i:s'),
                $this->apikey,
                $this->user->getSsoDiscordId(),
                $this->user->getUsername(),
                $this->request->getUri()
            ),
            FILE_APPEND
        );
    }

    /**
     * Set request ID's, which will either use Api Key or Client IP
     */
    private function setApiRequestIds()
    {
        $unique = (Object)[
            'static'  => sha1("ga_". ($this->apikey ?: $this->request->getClientIp())),
            'dynamic' => sha1("ga_". ($this->apikey ?: $this->request->getClientIp()) . time()),
            'unique'  => Uuid::uuid4()->toString(),
        ];

        ApiRequest::$idStatic  = $unique->static;
        ApiRequest::$idDynamic = $unique->dynamic;
        ApiRequest::$idUnique  = $unique->unique;
    }

    /**
     * Checks the request against valid API controllers
     */
    private function isApiController()
    {
        // check if app can access this endpoint
        if (!in_array($this->getRequestController(), self::API_CONTROLLERS)) {
            return false;
        }

        return true;
    }
    
    /**
     * States if an API key exists in the request
     */
    private function hasApiKey(): bool
    {
        return !empty($this->request->get(self::KEY_FIELD));
    }

    /**
     * Sets the static permissions for the user if an account exists
     */
    private function setApiPermissions()
    {
        if ($this->user) {
            ApiPermissions::set($this->user->getPermissions());
        }
    }

    /**
     * A single account gets X number of requests per second, this is account wide so they
     * must proxy their own service to avoid others from stealing their API Key.
     *
     * Rate limits are for the individual user and are account wide.
     */
    private function checkDeveloperRateLimit()
    {
        $key = "api_rate_limit_user_{$this->user->getId()}";
        $this->handleRateLimit($key, $this->user->getApiRateLimit());
    }
    
    /**
     * Check a users rate limit
     */
    private function checkUserRateLimit()
    {
        $ip  = md5($this->request->getClientIp());
        $key = "api_rate_limit_client_{$ip}";
        
        $this->handleRateLimit($key);
    }
    
    /**
     * Handle rate limit tracking
     */
    private function handleRateLimit($key, $limit = self::MAX_RATE_LIMIT_GLOBAL)
    {
        // current and last second
        $key = $key .'_v3_'. (int)date('s');
        
        // increment
        $count = (int)Redis::Cache()->get($key);
        $count++;
        Redis::Cache()->set($key, $count, 3);
        
        // throw exception if hit count too high
        if ($count > $limit) {
            throw new ApiRateLimitException(
                ApiRateLimitException::MESSAGE . " -- " . self::$idStatic
            );
        }
    }
    
    /**
     * Check that the user is not banned, if they provided a key
     */
    private function checkUserIsNotBanned()
    {
        if ($this->user->isBanned()) {
            throw new ApiAppBannedException();
        }
    }

    /**
     * Returns the controller of the current request
     */
    private function getRequestController()
    {
        return explode('::', $this->request->attributes->get('_controller'))[0];
    }

    /**
     * Returns the endpoint of the current request, eg: /character/1245 = character
     */
    private function getRequestEndpoint()
    {
        return strtolower(explode('/', $this->request->getPathInfo())[1]) ?? 'x';
    }
    
    /**
     * Send developer specific analytic data
     */
    private function sendDeveloperAnalyticData()
    {
        $key = trim($this->user->getApiAnalyticsKey());

        // check if user has an analytics key
        if (empty($key)) {
            return;
        }

        // User Google Analytics
        GoogleAnalytics::hit($key, $this->request->getPathInfo());
        GoogleAnalytics::event($key, 'Requests', 'Endpoint', $this->getRequestEndpoint());
        GoogleAnalytics::event($key, 'Requests', 'Language', Language::current());
    }

    /**
     * Records daily limit for requests
     */
    private function recordDailyLimit()
    {
        if (empty($this->apikey)) {
            return;
        }
        
        if (ApiPermissions::has(ApiPermissions::PERMISSION_KING) !== false) {
            return;
        }

        $cap  = 1000;
        $timestamp = date('zHi');
        $key  = "apikey_request_count_{$this->apikey}_{$timestamp}";

        $count = Redis::Cache()->get($key) ?: 0;
        $count = (int)$count;
        $count++;

        if ($count > $cap) {
            if (Redis::Cache()->get($key . "_alert") == null) {
                Redis::Cache()->set($key . "_alert", true, 3600);
                Discord::mog()->sendMessage(null, "The API Key: {$this->apikey} ({$this->user->getUsername()}) has performed over 1500 requests in the past 60 seconds. The rate limit for this key will be dramatically reduced.");
                
                $this->user->setApiRateLimit(2);
                $this->users->save($this->user);
            }

            throw new \Exception("You have reached the request hard-cap. Please re-think your API usage...");
        }

        Redis::Cache()->set($key, $count, 60);
    }
}
