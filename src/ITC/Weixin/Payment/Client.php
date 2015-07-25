<?php namespace ITC\Weixin\Payment;

use RuntimeException;
use Psr\Log\LoggerInterface;
use Psr\Http\Message\ResponseInterface as HttpResponseInterface;
use GuzzleHttp\ClientInterface as HttpClientInterface;
use GuzzleHttp\Client as HttpClient;

use ITC\Weixin\Payment\Contracts\Client as ClientInterface;
use ITC\Weixin\Payment\Contracts\Message as MessageInterface;
use ITC\Weixin\Payment\Contracts\HashGenerator as HashGeneratorInterface;
use ITC\Weixin\Payment\Contracts\Serializer as SerializerInterface;
use ITC\Weixin\Payment\Contracts\Command as CommandInterface;

class Client implements ClientInterface {

    private $app_id;
    private $mch_id;
    private $secret;
    private $public_key_path;
    private $private_key_path;

    private $logger;
    private $http;
    private $hashgen;
    private $serializer;
    private $cache;

    private $commands = [];

    /**
     * @param array $config
     * @return ITC\Weixin\Payment\Client
     */
    public static function instance(array $config=[])
    {
        $client = new static($config);

        $client->register(new Command\CreateUnifiedOrder());
        $client->register(new Command\CreateJavascriptParameters());

        return $client;
    }

    /**
     * @param array $config
     */
    public function __construct(array $config=[])
    {
        $this->app_id = $config['app_id'];
        $this->mch_id = $config['mch_id'];
        $this->secret = $config['secret'];
        $this->public_key_path = $config['public_key_path'];
        $this->private_key_path = $config['private_key_path'];

        !empty($config['secure']) && $this->secure();
    }

    /**
     * @param Psr\Log\LoggerInterface $logger
     * @return void
     */
    public function setLogger(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * @param void
     * @return Psr\Log\LoggerInterface $logger
     */
    public function getLogger()
    {
        // @codeCoverageIgnoreStart
        if (!$this->logger)
        {
            $this->logger = new DummyLogger();
        }
        // @codeCoverageIgnoreEnd

        return $this->logger;
    }

    /**
     * @param GuzzleHttp\ClientInterface $client
     * @return void
     */
    public function setHttpClient(HttpClientInterface $client)
    {
        $this->http = $client;
    }

    /**
     * @param void
     * @return GuzzleHttp\ClientInterface
     */
    public function getHttpClient()
    {
        // @codeCoverageIgnoreStart
        if (!$this->http)
        {
            $this->setHttpClient(new HttpClient());
        }
        // @codeCoverageIgnoreEnd

        return $this->http;
    }

    /**
     * @param ITC\Weixin\Contracts\HashGenerator $hashgen
     * @return void
     */
    public function setHashGenerator(HashGeneratorInterface $hashgen)
    {
        $this->hashgen = $hashgen;
    }

    /**
     * @param void
     * @return ITC\Weixin\Contracts\HashGenerator
     */
    public function getHashGenerator()
    {
        // @codeCoverageIgnoreStart
        if (!$this->hashgen)
        {
            $this->setHashGenerator(new HashGenerator($this->secret));
        }
        // @codeCoverageIgnoreEnd

        return $this->hashgen;
    }

    /**
     * @param ITC\Weixin\Contracts\Serializer
     * @return void
     */
    public function setSerializer(SerializerInterface $serializer)
    {
        $this->serializer = $serializer;
    }

    /**
     * @param void
     * @return ITC\Weixin\Contracts\SerializerInterface
     */
    public function getSerializer()
    {
        // @codeCoverageIgnoreStart
        if (!$this->serializer)
        {
            $this->setSerializer(new XmlSerializer());
        }
        // @codeCoverageIgnoreEnd

        return $this->serializer;
    }

    /**
     * @param void
     * @codeCoverageIgnore
     */
    public function secure($secure=true)
    {
        $this->secure = $secure;
        return $this;
    }

    /**
     * @param mixed $data
     * @return ITC\Weixin\Payment\Contracts\Message $message
     */
    public function createMessage($data=null)
    {
        if (is_string($data) && $data)
        {
            $data = $this->getSerializer->unserialize($data);
        }

        return new Message\Message($data, $this->getHashGenerator());
    }

    /**
     * @param string $url
     * @param ITC\Weixin\Payment\Contracts\Message $message
     * @param Psr\Http\Message\ResponseInterface $response
     * @return array
     */
    public function post($url, MessageInterface $message, HttpResponseInterface &$response=null)
    {
        $log = $this->getLogger();
        $serializer = $this->getSerializer();

        $this->prepareOutboundMessage($message);

        $reqbody = $serializer->serialize($message->toArray());

        // send a POST request (it's always a POST)
        $response = $this->getHttpClient()->post($url, ['body'=>$reqbody]);
        $status = (int) $response->getStatusCode();
        $resbody = $response->getBody();

        $log->info("[$status] POST $url", ['method'=>__METHOD__]);
        $log->debug('  req: '.$reqbody, ['method'=>__METHOD__]);
        $log->debug('  res: '.$resbody, ['method'=>__METHOD__]);

        if ($status < 200 || $status >= 300)
        {
            $msg = 'got unexpected HTTP response status '.$status;
            $log->error($msg, ['method'=>__METHOD__]);
            throw new UnexpectedValueException($msg);
        }

        $data = $serializer->unserialize($resbody);

        return $this->createMessage($data);
    }

    /**
     * Returns the Command identified by the supplied name
     * @param string $name
     * @return ITC\Weixin\Payment\Contracts\Command
     */
    public function command($name)
    {
        if (!isset($this->commands[$name]))
        {
            throw new RuntimeException('unknown command: '.$name);
        }

        return $this->commands[$name];
    }

    /**
     * Registers a Command on the client instance
     * @param ITC\Weixin\Payment\Contracts\Command $command
     * @return void
     */
    public function register(CommandInterface $command)
    {
        $command->setClient($this);

        $this->commands[$command->name()] = $command;
    }

    /**
     * @param ITC\Weixin\Payment\Contracts\Message $message
     * @return void
     */
    public function prepareOutboundMessage(MessageInterface $message)
    {
        $message->set('appid', $this->app_id);
        $message->set('mch_id', $this->mch_id);
        $message->get('nonce_str') || $message->set('nonce_str', static::uuid());
        $message->sign();
    }

    /**
     * Generates a pseudo-random UUID
     * @param void
     * @return string
     */
    protected static function uuid()
    {
        $data = openssl_random_pseudo_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}

