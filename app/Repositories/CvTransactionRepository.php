<?php
declare(strict_types=1);

namespace CvTailor\Repositories;

use PDO;

final class CvTransactionRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function create(
        string $originalCv,
        string $jobDescription,
        string $tailoredCv
    ): int {
        $statement = $this->pdo->prepare(
            'INSERT INTO cv_tailor_transactions
                (original_cv, job_description, tailored_cv)
             VALUES
                (:original_cv, :job_description, :tailored_cv)'
        );

        $statement->execute([
            ':original_cv' => $originalCv,
            ':job_description' => $jobDescription,
            ':tailored_cv' => $tailoredCv,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function findTailoredCv(int $transactionId): ?string
    {
        $statement = $this->pdo->prepare(
            'SELECT tailored_cv
             FROM cv_tailor_transactions
             WHERE id = :id
             LIMIT 1'
        );

        $statement->execute([':id' => $transactionId]);
        $record = $statement->fetch();

        if (!is_array($record) || !isset($record['tailored_cv'])) {
            return null;
        }

        return (string) $record['tailored_cv'];
    }
}
