<?php

declare(strict_types=1);

namespace PossiblePromise\QbHealthcare\Entity;

use MongoDB\BSON\Decimal128;
use MongoDB\BSON\Persistable;
use MongoDB\BSON\UTCDateTime;
use Webmozart\Assert\Assert;

final class PaymentInfo implements Persistable
{
    public function __construct(
        private Payer $payer,
        private ?\DateTime $billedDate = null,
        private ?\DateTime $paymentDate = null,
        private ?string $payment = null,
        private ?string $paymentRef = null,
        /** @var numeric-string|null */
        private ?string $copay = null,
        /** @var numeric-string|null */
        private ?string $coinsurance = null,
        /** @var numeric-string|null */
        private ?string $deductible = null,
        private ?\DateTime $postedDate = null
    ) {
    }

    public function getPayer(): Payer
    {
        return $this->payer;
    }

    /**
     * @return numeric-string|null
     */
    public function getCoinsurance(): ?string
    {
        return $this->coinsurance;
    }

    public function bsonSerialize(): array
    {
        return [
            'payer' => $this->payer,
            'billedDate' => $this->billedDate ? new UTCDateTime($this->billedDate) : null,
            'paymentDate' => $this->paymentDate ? new UTCDateTime($this->paymentDate) : null,
            'payment' => $this->payment ? new Decimal128($this->payment) : null,
            'paymentRef' => $this->paymentRef,
            'copay' => $this->copay ? new Decimal128($this->copay) : new Decimal128('0.00'),
            'coinsurance' => $this->coinsurance ? new Decimal128($this->coinsurance) : new Decimal128('0.00'),
            'deductible' => $this->deductible ? new Decimal128($this->deductible) : new Decimal128('0.00'),
            'postedDate' => $this->postedDate ? new UTCDateTime($this->postedDate) : null,
        ];
    }

    public function bsonUnserialize(array $data): void
    {
        Assert::isInstanceOf($data['payer'], Payer::class);
        Assert::nullOrIsInstanceOf($data['billedDate'], UTCDateTime::class);
        Assert::nullOrIsInstanceOf($data['paymentDate'], UTCDateTime::class);
        Assert::nullOrIsInstanceOf($data['payment'], Decimal128::class);
        Assert::nullOrString($data['paymentRef']);
        Assert::nullOrIsInstanceOf($data['copay'], Decimal128::class);
        Assert::nullOrIsInstanceOf($data['coinsurance'], Decimal128::class);
        Assert::nullOrIsInstanceOf($data['deductible'], Decimal128::class);
        Assert::nullOrIsInstanceOf($data['postedDate'], UTCDateTime::class);

        $this->payer = $data['payer'];

        if ($data['billedDate'] !== null) {
            $this->billedDate = $data['billedDate']->toDateTime();
        }

        if ($data['paymentDate'] !== null) {
            $this->paymentDate = $data['paymentDate']->toDateTime();
        }

        if ($data['payment'] !== null) {
            $this->payment = (string) $data['payment'];
        }

        $this->paymentRef = $data['paymentRef'];

        $copay = (string) $data['copay'];
        Assert::numeric($copay);
        $this->copay = $copay;

        $coinsurance = (string) $data['coinsurance'];
        Assert::numeric($coinsurance);
        $this->coinsurance = $coinsurance;

        $deductible = (string) $data['deductible'];
        Assert::numeric($deductible);
        $this->deductible = $deductible;

        if ($data['postedDate'] !== null) {
            $this->postedDate = $data['postedDate']->toDateTime();
        }
    }
}
