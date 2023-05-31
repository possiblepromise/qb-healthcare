<?php

declare(strict_types=1);

namespace PossiblePromise\QbHealthcare\Repository;

use MongoDB\Collection;
use PossiblePromise\QbHealthcare\Database\MongoClient;
use PossiblePromise\QbHealthcare\Entity\Charge;
use PossiblePromise\QbHealthcare\Entity\PaymentInfo;
use PossiblePromise\QbHealthcare\ValueObject\ChargeLine;
use PossiblePromise\QbHealthcare\ValueObject\ChargesImported;

final class ChargesRepository
{
    private Collection $charges;

    public function __construct(MongoClient $client, private readonly PayersRepository $payers)
    {
        $this->charges = $client->getDatabase()->charges;
    }

    /**
     * @param ChargeLine[] $lines
     */
    public function import(array $lines): ChargesImported
    {
        $imported = new ChargesImported();

        foreach ($lines as $line) {
            $payer = $this->payers->findOneByNameAndService($line->primaryPayer, $line->billingCode);
            if ($payer === null) {
                throw new \UnexpectedValueException('No payer found');
            }

            $service = $payer->getServices()[0];

            $paymentInfo = new PaymentInfo(
                payer: $payer,
                billedDate: $line->primaryBilledDate,
                paymentDate: $line->primaryPaymentDate,
                payment: $line->primaryPayment,
                paymentRef: $line->primaryPaymentRef,
                copay: $line->copay,
                coinsurance: $line->coinsurance,
                deductible: $line->deductible,
                postedDate: $line->primaryPostedDate
            );

            $charge = new Charge(
                chargeLine: $line->chargeLine,
                serviceDate: $line->dateOfService,
                clientName: $line->clientName,
                service: $service,
                billedAmount: $line->billedAmount,
                contractAmount: $line->contractAmount,
                billedUnits: $line->billedUnits,
                primaryPaymentInfo: $paymentInfo,
                payerBalance: $line->payerBalance
            );

            $result = $this->charges->updateOne(
                ['_id' => $charge->getChargeLine()],
                ['$set' => $charge],
                ['upsert' => true]
            );

            $imported->new += $result->getUpsertedCount() ?? 0;
            $imported->modified += $result->getModifiedCount() ?? 0;
        }

        return $imported;
    }
}
