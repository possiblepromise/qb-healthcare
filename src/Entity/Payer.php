<?php

declare(strict_types=1);

namespace PossiblePromise\QbHealthcare\Entity;

use MongoDB\BSON\Persistable;
use MongoDB\Model\BSONArray;
use PossiblePromise\QbHealthcare\ValueObject\PayerLine;
use Webmozart\Assert\Assert;

final class Payer implements Persistable
{
    private string $id;
    private string $name;
    private string $type;
    private ?string $address = null;
    private ?string $city = null;
    private ?string$state = null;
    private ?string $zip = null;
    private ?string $phone = null;
    private ?string $email = null;

    /**
     * @var Service[]
     */
    private array $services = [];

    public function __construct(string $id, string $name, string $type)
    {
        $this->id = $id;
        $this->name = $name;
        $this->type = $type;
    }

    public static function fromLine(PayerLine $line): self
    {
        $payer = new self($line->payerId, $line->payerName, $line->type);

        if ($line->address) {
            $payer->address = $line->address;
        }

        if ($line->city) {
            $payer->city = $line->city;
        }

        if ($line->state) {
            $payer->state = $line->state;
        }

        if ($line->zip) {
            $payer->zip = $line->zip;
        }

        if ($line->phone) {
            $payer->phone = $line->phone;
        }

        if ($line->email) {
            $payer->email = $line->email;
        }

        return $payer;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): self
    {
        $this->type = $type;

        return $this;
    }

    public function getAddress(): ?string
    {
        return $this->address;
    }

    public function setAddress(string $address): self
    {
        $this->address = $address;

        return $this;
    }

    public function getCity(): ?string
    {
        return $this->city;
    }

    public function setCity(string $city): self
    {
        $this->city = $city;

        return $this;
    }

    public function getState(): ?string
    {
        return $this->state;
    }

    public function setState(string $state): self
    {
        $this->state = $state;

        return $this;
    }

    public function getZip(): ?string
    {
        return $this->zip;
    }

    public function setZip(string $zip): self
    {
        $this->zip = $zip;

        return $this;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function setPhone(string $phone): self
    {
        $this->phone = $phone;

        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): self
    {
        $this->email = $email;

        return $this;
    }

    /**
     * @return Service[]
     */
    public function getServices(): array
    {
        return $this->services;
    }

    public function addService(Service $service): self
    {
        $this->services[] = $service;

        return $this;
    }

    public function bsonSerialize(): array
    {
        return [
            '_id' => $this->id,
            'name' => $this->name,
            'type' => $this->type,
            'address' => $this->address,
            'city' => $this->city,
            'state' => $this->state,
            'zip' => $this->zip,
            'phone' => $this->phone,
            'email' => $this->email,
            'services' => $this->services,
        ];
    }

    public function bsonUnserialize(array $data): void
    {
        Assert::string($data['_id']);
        Assert::string($data['name']);
        Assert::string($data['type']);
        Assert::nullOrString($data['address']);
        Assert::nullOrString($data['city']);
        Assert::nullOrString($data['state']);
        Assert::nullOrString($data['zip']);
        Assert::nullOrString($data['phone']);
        Assert::nullOrString($data['email']);
        Assert::isInstanceOfAny($data['services'], [BSONArray::class, Service::class]);

        $this->id = $data['_id'];
        $this->name = $data['name'];
        $this->type = $data['type'];
        $this->address = $data['address'];
        $this->city = $data['city'];
        $this->state = $data['state'];
        $this->zip = $data['zip'];
        $this->phone = $data['phone'];
        $this->email = $data['email'];

        if ($data['services'] instanceof BSONArray) {
            $services = $data['services']->getArrayCopy();
            Assert::allIsInstanceOf($services, Service::class);
            $this->services = $services;
        } elseif ($data['services'] instanceof Service) {
            $this->services = [$data['services']];
        }
    }
}
