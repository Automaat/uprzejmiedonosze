<?PHP namespace generator;
require(__DIR__ . '/../dataclasses/Petition.php');

const TABLE = 'petition';

/**
 * Returns the number of applications per specified $plate.
 */
function get(string $petitionId): Petition {
    $petitionJson = \store\get(TABLE, $petitionId);
    return Petition::withJson($petitionJson);
}

function set(Petition $petition) {
    \store\set(TABLE, $petition->id, json_encode($petition));
}

function getTargetStats() {
    $sql = <<<SQL
    select json_extract(value, '$.target'),
        count(key)
    from petition
    where json_extract(value, '$.status') = 'generated'
    group by 1
SQL;
    $stats = \store\query($sql)->fetchAll(\PDO::FETCH_KEY_PAIR);
    return $stats;
}

function getRecipientStats() {
    $sql = <<<SQL
    select json_extract(value, '$.recipient'),
        count(key)
    from petition
    where ifnull(json_extract(value, '$.recipient'), "") <> ""
        and json_extract(value, '$.status') = 'generated'
    group by 1
SQL;
    $stats = \store\query($sql)->fetchAll(\PDO::FETCH_KEY_PAIR);
    return $stats;
}
