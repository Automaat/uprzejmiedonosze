<?php namespace generator;

require_once(__DIR__ . '/AbstractHandler.php');
require_once(__DIR__ . '/../data.php');

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class ApiAiHandler extends \AbstractHandler {
    private string $model = 'gpt-5-nano';
    private string $project = 'proj_bZw0xOrleo011MupIRBw4pQC';

    protected function jsonResponse(Response $response, $data = null, int $status = 200): Response {
        $payload = [
            'status' => $status >= 200 && $status < 300 ? 'success' : 'error',
            'data' => $data
        ];

        return $this->renderJson($response, $payload, $status);
    }


    public function stream(Request $request, Response $response, array $args): Response {
        // Get data from either POST body or GET parameters
        if ($request->getMethod() === 'GET') {
            $queryParams = $request->getQueryParams();
            $data = [
                'topics' => json_decode($queryParams['topics'] ?? '[]', true),
                'form_type' => $queryParams['form_type'] ?? '',
                'target' => $queryParams['target'] ?? ''
            ];
        } else {
            $data = $request->getParsedBody();
        }
        
        // Validate input
        $required = ['topics', 'form_type', 'target'];
        $missing = [];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                $missing[] = $field;
            }
        }
        
        if (!empty($missing)) {
            return $this->jsonResponse($response, [
                'error' => 'Missing required fields',
                'missing' => $missing
            ], 400);
        }
        
        $user = $request->getAttribute('user');
        $isPatron = $user->isFormerPatron() || $user->isPatron() || $user->isAdmin();
        if (!$isPatron) {
            return $this->jsonResponse($response, [
                'error' => 'You must be a patron to use this feature',
                'missing' => ['patron']
            ], 403);
        }

        try {
            $topics = (array)$data['topics'];
            $formType = $data['form_type'];
            $target = $data['target'];
            $name = $user->data->name;
            $city = $user->data->address;

            $petition = Petition::withData($topics, $formType, $target, $name, $city);
            
            list($systemPrompt, $contentPrompt) = $this->getPrompt($petition);

            $petition->setPrompts($systemPrompt, $contentPrompt);
            
            
            $client = \OpenAI::client(apiKey: OPENAI_API_KEY, project: $this->project);
            
            $stream = $client->chat()->createStreamed([
                'model' => $this->model,
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $contentPrompt]
                ],
                'stream' => true
            ]);

            // Disable output buffering
            if (ob_get_level() > 0) {
                ob_end_flush();
            }
            
            // Create a custom stream handler
            $streamHandler = function() use ($stream, $petition, $systemPrompt, $contentPrompt) {
                // Start output buffering
                if (ob_get_level() > 0) {
                    ob_end_clean();
                }
                
                // Set headers for streaming
                header('Content-Type: text/event-stream');
                header('Cache-Control: no-cache');
                header('X-Accel-Buffering: no');
                header('Connection: keep-alive');
                
                $completion = '';
                
                // Process the stream
                foreach ($stream as $chunk) {
                    $content = $chunk->choices[0]->delta->content ?? '';
                    if (!empty($content)) {
                        $completion .= $content;
                        echo "data: " . json_encode(['content' => $content]) . "\n\n";
                        if (ob_get_level() > 0) {
                            ob_flush();
                        }
                        flush();
                    }
                }
                
                // Send done signal
                echo "data: [DONE]\n\n";
                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();

                $price = $this->calculateEstimation($systemPrompt, $contentPrompt, $completion);
                $petition->setGenerated($completion, $price);
                \generator\set($petition);

                exit;
            };
            
            // Execute the stream handler
            $streamHandler();
            
            // This return is a fallback in case output buffering is disabled
            return $response->withHeader('Content-Type', 'text/plain')
                           ->withStatus(200);

        } catch (\Exception $e) {
            return $this->jsonResponse($response, [
                'error' => 'Failed to stream content',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    private function getPrompt(Petition $petition): array {
        global $TOPICS, $TARGETS, $FORMS, $INTRO;

        $topicsStr = "";
        foreach ($petition->topics as $topicId) {
            if (!isset($TOPICS[$topicId])) continue;
            
            $topic = $TOPICS[$topicId];
            $topicsStr .= "\n\n## {$topic['title']}\n{$topic['desc']}\n";
            
            if (!empty($topic['topics'])) {
                $topicsStr .= "  - " . implode("\n  - ", $topic['topics']);
            }

            if ($petition->formType === 'proposal' && !empty($topic['law'])) {
                $topicsStr .= "\n### Propozycja zmiany przepisów:\n{$topic['law']}";
            }
        }

        $systemPrompt = "Jesteś mieszkańcem i obywatelem zirytowanym powszechnym łamaniem przepisów związanych z parkowaniem przez kierowców";
        $targetTitle = $TARGETS[$petition->target]['title'] ?? $petition->target;
        $intro = $petition->formType !== 'email' ? "\n\n# Pozostałe\n\nMożesz użyć także tych materiałów:\n\n{$INTRO}" : "";

        $formText = $FORMS[$petition->formType] ?? $petition->formType;
        $contentPrompt = "# Zadanie\n\nNapisz $formText do $targetTitle w sprawie wadliwych przepisów regulujących parkowanie w Polsce.\n\n# Uwagi ogólne\n\nUżywaj przykładów i propozycji podanych poniżej. Nie wymyślaj własnych propozycji.\n\nNie stosuj formatowania markdown albo ikon. Czysty tekst.\n\nPodpisz dokument jako:\n{$petition->name}\n{$petition->city}\n\n# Szczegóły do poruszenia\n$topicsStr\n\n$intro\n\n# Uwagi końcowe\n\n- Pisz w pierwszej osobie liczby pojedynczej.\n- Pisz w stylu oficjalnym, ale nie przesadnie formalnym.\n- Pisz krótkie i konkretne zdania.\n- Używaj akapitów i wypunktowań.\n- Używaj podtytułów do podziału na sekcje.\n- Bądź zwięzły i na temat.\n";

        return [$systemPrompt, $contentPrompt];
    }

    private function calculatePrice(object $usage): float {
        global $MODEL_PRICING;

        $pricing = $MODEL_PRICING[$this->model];
        $inputTokens = $usage->promptTokens;
        $outputTokens = $usage->completionTokens;

        return ($inputTokens * $pricing['prompt'] + $outputTokens * $pricing['completion']) / 1_000_000;
    }

    private function calculateEstimation(string $systemPrompt, string $contentPrompt, string $response): float {
        global $MODEL_PRICING;

        function count_tokens(string $text): int {
            return mb_strlen($text, 'UTF-8') / 3 * 2;
        }

        $pricing = $MODEL_PRICING[$this->model];
        $inputTokens = count_tokens($systemPrompt) + count_tokens($contentPrompt);
        $outputTokens = count_tokens($response);

        return ($inputTokens * $pricing['prompt'] + $outputTokens * $pricing['completion']) / 1_000_000;
    }
        

    /**
     * Get available topics
     */
    public function getTopics(Request $request, Response $response, array $args): Response {
        global $TOPICS;
        return $this->renderJson($response, $TOPICS);
    }

    /**
     * Get available forms
     */
    public function getForms(Request $request, Response $response, array $args): Response {
        global $FORMS;
        return $this->renderJson($response, $FORMS);
    }

    /**
     * Get available targets
     */
    public function getTargets(Request $request, Response $response, array $args): Response {
        global $TARGETS;        
        return $this->renderJson($response, $TARGETS);
    }
}
