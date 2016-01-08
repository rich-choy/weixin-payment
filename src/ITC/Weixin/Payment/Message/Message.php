<?php

namespace ITC\Weixin\Payment\Message;

use RuntimeException;
use ITC\Weixin\Payment\Contracts\Message as MessageInterface;
use ITC\Weixin\Payment\Contracts\HashGenerator as HashGeneratorInterface;
use ITC\Weixin\Payment\Contracts\Serializer as SerializerInterface;
use ITC\Weixin\Payment\XmlSerializer;

class Message implements MessageInterface
{
    /**
     * @var array
     */
    private $data = [];

    /**
     * @var array
     */
    private $orgData = [];

    /**
     * @var ITC\Weixin\Payment\Contracts\HashGenerator
     */
    private $hashgen;

    /**
     * @var ITC\Weixin\Payment\Contracts\Serializer
     */
    private $serializer;

    /**
     * @param ITC\Weixin\Payment\Contracts\HashGenerator $hashgen
     * @param array                                      $data
     */
    public function __construct($data = null,
        HashGeneratorInterface $hashgen = null,
        SerializerInterface $serializer = null)
    {
        $hashgen && $this->setHashGenerator($hashgen);
        $serializer && $this->setSerializer($serializer);

        if ($data) {
            foreach ((array) $data as $attr => $value) {
                $this->set($attr, $value);
            }
        }
    }

    /**
     * @param string $attr
     *
     * @return mixed
     */
    public function get($attr, $origin = false)
    {
        if ($origin) {
            return isset($this->orgData[$attr]) ? $this->orgData[$attr] : null;
        } else {
            return isset($this->data[$attr]) ? $this->data[$attr] : null;
        }
    }

    /**
     * @param string $attr
     * @param mixed  $value
     */
    public function set($attr, $value)
    {
        $this->orgData[$attr] = $value;
        if (is_array($value)) {
            $value = $this->createPseudoQuery($value);
        }
        $this->data[$attr] = $value;
    }

    /**
     * @param string $attr
     */
    public function clear($attr)
    {
        unset($this->orgData[$attr]);
        unset($this->data[$attr]);
    }

    /**
     * @param void
     */
    public function sign()
    {
        unset($this->data['sign']);
        $this->data['sign'] = $this->getHashGenerator()->hash($this->data);
    }

    /**
     * @param void
     *
     * @return bool
     */
    public function authenticate()
    {
        if ($signature = $this->get('sign')) {
            $data = $this->data;
            unset($data['sign']);

            return $signature === $this->getHashGenerator()->hash($data);
        }

        return false;
    }

    /**
     * @param void
     *
     * @return array
     */
    public function toArray($origin = false)
    {
        if ($origin) {
            return $this->orgData;
        } else {
            return $this->data;
        }
    }

    /**
     * @param void
     *
     * @return array
     */
    public function jsonSerialize()
    {
        return $this->data;
    }

    /**
     * {i: 'am', not: 'url encoded'}  -> "i=am&not=url encoded".
     *
     * @param array $data
     *
     * @return string
     */
    private function createPseudoQuery(array $data)
    {
        $tokens = [];

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $tokens[] = $this->createPseudoQuery($value);
            } else {
                $tokens[] = $key.'='.$value;
            }
        }

        return implode('&', $tokens);
    }

    /**
     * @param void
     *
     * @return string
     */
    public function serialize()
    {
        return $this->getSerializer()->serialize($this->data);
    }

    /**
     * @param ITC\Weixin\Payment\Contracts\Serializer $serializer
     */
    public function setSerializer(SerializerInterface $serializer)
    {
        $this->serializer = $serializer;
    }

    /**
     * @param void
     *
     * @return ITC\Weixin\Payment\Contracts\Serializer
     */
    public function getSerializer()
    {
        if (!$this->serializer) {
            $this->serializer = new XmlSerializer();
        }

        return $this->serializer;
    }

    /**
     * @param ITC\Weixin\Payment\Contracts\HashGenerator $hashgen
     */
    public function setHashGenerator(HashGeneratorInterface $hashgen)
    {
        $this->hashgen = $hashgen;
    }

    /**
     * @param void
     * @param ITC\Weixin\Payment\Contracts\HashGenerator
     */
    public function getHashGenerator()
    {
        if (!$this->hashgen) {
            throw new RuntimeException('a hash generator has not been assigned');
        }

        return $this->hashgen;
    }
}
