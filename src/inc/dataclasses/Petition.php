<?PHP namespace generator;

require_once(__DIR__ . '/JSONObject.php');

class Petition extends \JSONObject {
    protected const USE_ARRAY_FLOW = true;

    private function __construct() {
    }

    public static function withJson(string $json=null) {
        $instance = new self();
        $instance->__fromJson($json);
        return $instance;
    }

    public static function withData(array $topics,
        string $formType,
        string $target,
        string $name,
        string $city) {

        $instance = new self();

        $instance->id = substr(str_replace(['+', '/', '='], '', base64_encode(random_bytes(12))), 0, 12);
        $instance->added = date(DT_FORMAT);
        $instance->topics = $topics;
        $instance->formType = $formType;
        $instance->target = $target;
        $instance->name = $name;
        $instance->city = $city;
        $instance->status = 'draft';

        return $instance;
    }

    public function setGenerated(string $text, float $price): void {
        $this->text = $text;
        $this->price = $price;
        $this->status = 'generated';
    }

    public function setPrompts(string $systemPrompt, string $contentPrompt): void {
        $this->systemPrompt = $systemPrompt;
        $this->contentPrompt = $contentPrompt;
    }

    public string $id;
    public array $topics;
    public string $formType;
    public string $target;
    public string $name;
    public string $city;
    public string $added;
    public float $price;
    public string $status;
    public string $text;
    public string $systemPrompt;
    public string $contentPrompt;
}
