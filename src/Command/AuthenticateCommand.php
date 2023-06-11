<?php

declare(strict_types=1);

namespace PossiblePromise\QbHealthcare\Command;

use PossiblePromise\QbHealthcare\QuickBooks;
use PossiblePromise\QbHealthcare\Repository\CompaniesRepository;
use PossiblePromise\QbHealthcare\ValueObject\Company;
use PossiblePromise\QbHealthcare\ValueObject\Token;
use QuickBooksOnline\API\Core\HttpClients\FaultHandler;
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
    public function __construct(private QuickBooks $qb, private CompaniesRepository $companies)
    {
        parent::__construct();
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

        $dataService = $this->qb->getDataService(true);

        /** @var OAuth2LoginHelper $loginHelper */
        $loginHelper = $dataService->getOAuth2LoginHelper();

        $accessToken = $loginHelper->exchangeAuthorizationCodeForToken($code, $realmId);
        $dataService->updateOAuth2Token($accessToken);
        $companyInfo = $dataService->getCompanyInfo();
        /** @var FaultHandler|false $error */
        $error = $dataService->getLastError();
        if ($error) {
            /** @psalm-suppress InvalidOperand */
            $io->error($error->getIntuitErrorMessage());

            return Command::FAILURE;
        }

        /** @var string $accessTokenExpiration */
        $accessTokenExpiration = $accessToken->getAccessTokenExpiresAt();
        /** @var string $refreshTokenExpiration */
        $refreshTokenExpiration = $accessToken->getRefreshTokenExpiresAt();

        $company = new Company(
            realmId: $accessToken->getRealmID(),
            companyName: $companyInfo->CompanyName,
            accessToken: Token::fromOauth($accessToken->getAccessToken(), $accessTokenExpiration),
            refreshToken: Token::fromOauth($accessToken->getRefreshToken(), $refreshTokenExpiration)
        );

        $this->companies->save($company);

        $io->success('You have been authenticated.');

        return Command::SUCCESS;
    }
}
