<?php

declare(strict_types=1);

namespace PossiblePromise\QbHealthcare\Command;

use MongoDB\BSON\UTCDateTime;
use PossiblePromise\QbHealthcare\Database\MongoClient;
use PossiblePromise\QbHealthcare\QuickBooks;
use QuickBooksOnline\API\Core\OAuth\OAuth2\OAuth2LoginHelper;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'auth',
    description: 'Authenticate with QuickBooks.'
)]
final class AuthenticateCommand extends Command
{
    private \MongoDB\Collection $companies;

    public function __construct(private QuickBooks $qb, MongoClient $client)
    {
        parent::__construct();

        $this->companies = $client->getDatabase()->companies;
    }

    protected function configure(): void
    {
        $this
            ->setHelp('Allows you to authenticate with QuickBooks.')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Authenticate');

        $code = (string) $io->ask('Authorization code');
        $realmId = (string) $io->ask('Realm ID');

        $dataService = $this->qb->getDataService();
        /** @var OAuth2LoginHelper $loginHelper */
        $loginHelper = $dataService->getOAuth2LoginHelper();

        $accessToken = $loginHelper->exchangeAuthorizationCodeForToken($code, $realmId);
        $dataService->updateOAuth2Token($accessToken);
        $companyInfo = $dataService->getCompanyInfo();

        /** @var string $accessTokenExpiration */
        $accessTokenExpiration = $accessToken->getAccessTokenExpiresAt();
        /** @var string $refreshTokenExpiration */
        $refreshTokenExpiration = $accessToken->getRefreshTokenExpiresAt();
        $record = [
            'companyName' => $companyInfo->CompanyName,
            'accessToken' => [
                'token' => $accessToken->getAccessToken(),
                'expires' => $this->formatDate($accessTokenExpiration),
            ],
            'refreshToken' => [
                'token' => $accessToken->getRefreshToken(),
                'expires' => $this->formatDate($refreshTokenExpiration),
            ],
            'realmId' => $accessToken->getRealmID(),
        ];

        $this->companies->updateOne(
            ['realmId' => $accessToken->getRealmID()],
            ['$set' => $record],
            ['upsert' => true]
        );

        $io->success('You have been authenticated.');

        return Command::SUCCESS;
    }

    private function formatDate(string $dateString): UTCDateTime
    {
        $date = \DateTimeImmutable::createFromFormat('Y/m/d H:i:s', $dateString);
        if ($date === false) {
            throw new \InvalidArgumentException('Date could not be parsed.');
        }

        return new UTCDateTime($date);
    }
}
