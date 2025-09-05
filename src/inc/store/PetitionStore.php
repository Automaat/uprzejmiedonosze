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
