<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * A contributor as the application groups them, shared across repositories.
 *
 * identity_key is "github:<numeric user id>", "email:<email>", "name:<name>" or "unknown".
 * AuthorIdentity::keyFor() holds the rules; see DECISIONS.md for why.
 *
 * Mapping only, hence read-only for the ORM: AuthorIdentityResolver upserts authors with
 * one SQL statement per page, and ContributorStats reads them with SQL.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'authors')]
#[ORM\UniqueConstraint(name: 'uniq_authors_provider_identity', columns: ['provider', 'identity_key'])]
class Author
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 32)]
    private string $provider;

    #[ORM\Column(length: 320)]
    private string $identityKey;

    /** The provider's numeric account id, when the commit is linked to an account. */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $externalId;

    /** Display only. Can change when the user renames their account. */
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $login;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $name;

    #[ORM\Column(length: 320, nullable: true)]
    private ?string $email;
}
