<?php

declare(strict_types=1);

namespace PrismPHP\Prism\Providers\Mistral\Handlers;

use Illuminate\Http\Client\PendingRequest;
use PrismPHP\Prism\Concerns\CallsTools;
use PrismPHP\Prism\Enums\FinishReason;
use PrismPHP\Prism\Exceptions\PrismException;
use PrismPHP\Prism\Providers\Mistral\Concerns\MapsFinishReason;
use PrismPHP\Prism\Providers\Mistral\Concerns\ValidatesResponses;
use PrismPHP\Prism\Providers\Mistral\Maps\MessageMap;
use PrismPHP\Prism\Providers\Mistral\Maps\ToolChoiceMap;
use PrismPHP\Prism\Providers\Mistral\Maps\ToolMap;
use PrismPHP\Prism\Text\Request;
use PrismPHP\Prism\Text\Response;
use PrismPHP\Prism\Text\ResponseBuilder;
use PrismPHP\Prism\Text\Step;
use PrismPHP\Prism\ValueObjects\Messages\AssistantMessage;
use PrismPHP\Prism\ValueObjects\Messages\ToolResultMessage;
use PrismPHP\Prism\ValueObjects\Meta;
use PrismPHP\Prism\ValueObjects\ToolCall;
use PrismPHP\Prism\ValueObjects\ToolResult;
use PrismPHP\Prism\ValueObjects\Usage;
use Throwable;

class Text
{
    use CallsTools;
    use MapsFinishReason;
    use ValidatesResponses;

    protected ResponseBuilder $responseBuilder;

    public function __construct(protected PendingRequest $client)
    {
        $this->responseBuilder = new ResponseBuilder;
    }

    public function handle(Request $request): Response
    {
        $data = $this->sendRequest($request);

        $this->validateResponse($data);

        $responseMessage = new AssistantMessage(
            data_get($data, 'choices.0.message.content') ?? '',
            $this->mapToolCalls(data_get($data, 'choices.0.message.tool_calls', [])),
        );

        $this->responseBuilder->addResponseMessage($responseMessage);

        $request->addMessage($responseMessage);

        return match ($this->mapFinishReason($data)) {
            FinishReason::ToolCalls => $this->handleToolCalls($data, $request),
            FinishReason::Stop => $this->handleStop($data, $request),
            default => throw PrismException::providerResponseError('Invalid tool choice'),
        };
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleToolCalls(array $data, Request $request): Response
    {
        $toolResults = $this->callTools(
            $request->tools(),
            $this->mapToolCalls(data_get($data, 'choices.0.message.tool_calls', [])),
        );

        $request->addMessage(new ToolResultMessage($toolResults));

        $this->addStep($data, $request, $toolResults);

        if ($this->shouldContinue($request)) {
            return $this->handle($request);
        }

        return $this->responseBuilder->toResponse();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleStop(array $data, Request $request): Response
    {
        $this->addStep($data, $request);

        return $this->responseBuilder->toResponse();
    }

    protected function shouldContinue(Request $request): bool
    {
        if ($request->maxSteps() === 0) {
            return true;
        }

        return $this->responseBuilder->steps->count() < $request->maxSteps();
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  ToolResult[]  $toolResults
     */
    protected function addStep(array $data, Request $request, array $toolResults = []): void
    {
        $this->responseBuilder->addStep(new Step(
            text: data_get($data, 'choices.0.message.content') ?? '',
            finishReason: $this->mapFinishReason($data),
            usage: new Usage(
                data_get($data, 'usage.prompt_tokens'),
                data_get($data, 'usage.completion_tokens'),
            ),
            meta: new Meta(
                id: data_get($data, 'id'),
                model: data_get($data, 'model'),
            ),
            messages: $request->messages(),
            toolResults: $toolResults,
            toolCalls: $this->mapToolCalls(data_get($data, 'choices.0.message.tool_calls', [])),
            additionalContent: [],
            systemPrompts: $request->systemPrompts(),
        ));
    }

    /**
     * @return array<string, mixed>
     */
    protected function sendRequest(Request $request): array
    {
        try {
            $response = $this->client->post('chat/completions', array_filter([
                'model' => $request->model(),
                'messages' => (new MessageMap($request->messages(), $request->systemPrompts()))(),
                'tools' => ToolMap::map($request->tools()),
                'temperature' => $request->temperature(),
                'max_tokens' => $request->maxTokens(),
                'top_p' => $request->topP(),
                'tool_choice' => ToolChoiceMap::map($request->toolChoice()),
            ]));

            return $response->json();
        } catch (Throwable $e) {
            throw PrismException::providerRequestError($request->model(), $e);
        }
    }

    /**
     * @param  array<mixed>|null  $toolCalls
     * @return array<mixed>
     */
    protected function mapToolCalls(?array $toolCalls): array
    {
        if (! $toolCalls) {
            return [];
        }

        return array_map(fn ($toolCall): \PrismPHP\Prism\ValueObjects\ToolCall => new ToolCall(
            id: data_get($toolCall, 'id'),
            name: data_get($toolCall, 'function.name'),
            arguments: data_get($toolCall, 'function.arguments'),
        ), $toolCalls);
    }
}
