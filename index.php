<?php

// Lida com erros para o log do Cloud Logging
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    error_log("PHP Error: [$errno] $errstr in $errfile on line $errline");
    return false; // Retorna false para que o handler de erro padrão do PHP continue
});

// Inclui o autoloader do Composer para carregar as classes da biblioteca
require __DIR__ . '/vendor/autoload.php';

// Usa as classes necessárias das bibliotecas
use Telegram\Bot\Api;
use Telegram\Bot\Keyboard\Keyboard;
use Google\Cloud\Firestore\FirestoreClient;
use Google\Cloud\Firestore\Transaction;

// --- Configurações do Bot e Google Cloud ---
// Obtém o token do bot do Telegram de uma variável de ambiente
$bot_token = getenv('TELEGRAM_BOT_TOKEN');
// Obtém o ID do projeto do Google Cloud de uma variável de ambiente
$project_id = getenv('GOOGLE_CLOUD_PROJECT');

// Verifica se as variáveis de ambiente essenciais estão configuradas
if (!$bot_token || !$project_id) {
    error_log('Erro: Variáveis de ambiente TELEGRAM_BOT_TOKEN ou GOOGLE_CLOUD_PROJECT não estão configuradas!');
    http_response_code(500); // Responde com erro interno do servidor
    die(json_encode(['error' => 'Configuração do bot ausente.']));
}

// --- Inicialização das APIs ---
$telegram = new Api($bot_token); // Inicializa a API do Telegram
$firestore = new FirestoreClient(['projectId' => $project_id]); // Inicializa o cliente Firestore
$collection = $firestore->collection('links'); // Define a coleção 'links' no Firestore

try {
    // 1. Recebe a atualização (Update) enviada pelo Telegram via Webhook
    $update = $telegram->getWebhookUpdate();

    // --- Lógica para MANUSEAR MENSAGENS (ex: comando /link) ---
    if ($update->getMessage()) {
        $message = $update->getMessage();
        $chatId = $message->getChat()->getId();
        $text = $message->getText();

        // Se a mensagem for o comando '/link'
        if (str_starts_with($text, '/link')) { // <-- ALTERADO AQUI: de /enviar_link para /link
            // Extrai o link da mensagem após o comando
            $parts = explode(' ', $text, 2); // Divide em até 2 partes
            $linkUrl = $parts[1] ?? ''; // Pega a segunda parte como o link

            // Validação simples do link (pode ser mais robusta)
            if (filter_var($linkUrl, FILTER_VALIDATE_URL)) {
                $linkId = uniqid('link_'); // Gera um ID único para o link no Firestore

                // Salva o link no Firestore com 0 cliques iniciais
                $docRef = $collection->document($linkId);
                $docRef->set([
                    'url' => $linkUrl,
                    'clicks' => 0,
                    'chat_id' => $chatId,
                    'message_id' => null, // Será preenchido após o envio da mensagem
                ]);

                // Cria o botão inline. O 'callback_data' é o nosso linkId para rastreamento.
                // O 'url' é o link real que o usuário acessará.
                $keyboard = Keyboard::make()->inline()
                    ->row([
                        Keyboard::inlineButton(['text' => 'Cliques: 0', 'url' => $linkUrl, 'callback_data' => $linkId])
                    ]);

                // Envia a mensagem com o botão
                $sentMessage = $telegram->sendMessage([
                    'chat_id'      => $chatId,
                    'text'         => '🔗 **Novo Link Rastreado!** Clique no botão abaixo para acessar:',
                    'reply_markup' => $keyboard,
                    'parse_mode'   => 'Markdown' // Permite formatação como negrito
                ]);

                // Atualiza o documento no Firestore com o message_id da mensagem que foi enviada
                // Isso é crucial para poder editar a mensagem depois
                $docRef->set([
                    'message_id' => $sentMessage->getMessageId(),
                ], ['merge' => true]);

                error_log("Comando /link executado. Link '{$linkUrl}' com ID '{$linkId}' enviado para o chat {$chatId}.");
            } else {
                $telegram->sendMessage([
                    'chat_id' => $chatId,
                    'text'    => 'Por favor, forneça um link válido após o comando /link. Ex: `/link https://www.exemplo.com`', // <-- ALTERADO AQUI
                    'parse_mode' => 'Markdown'
                ]);
            }
        } else {
            // Resposta padrão para outras mensagens de texto
            $telegram->sendMessage([
                'chat_id' => $chatId,
                'text'    => 'Olá! Envie um link usando o comando `/link SEU_LINK_AQUI` para eu rastrear os cliques.', // <-- ALTERADO AQUI
                'parse_mode' => 'Markdown'
            ]);
        }
    }

    // --- Lógica para MANUSEAR CLIQUES EM BOTÕES INLINE (callback_query) ---
    if ($update->getCallbackQuery()) {
        $callbackQuery = $update->getCallbackQuery();
        $callbackData = $callbackQuery->getData(); // Os dados que definimos no botão (o linkId)
        $queryId = $callbackQuery->getId(); // ID da query de callback, necessário para responder ao Telegram

        $chatId = $callbackQuery->getMessage()->getChat()->getId();
        $messageId = $callbackQuery->getMessage()->getMessageId();

        // O callbackData é o linkId que salvamos no Firestore
        $linkId = $callbackData;

        // Executa a transação no Firestore para garantir a atomicidade da atualização de cliques
        $firestore->runTransaction(function (Transaction $transaction) use ($collection, $linkId, $chatId, $messageId, $telegram, $queryId) {
            $docRef = $collection->document($linkId);
            $snapshot = $transaction->get($docRef);

            if ($snapshot->exists()) {
                $data = $snapshot->data();
                $currentClicks = $data['clicks'] ?? 0;
                $newClicks = $currentClicks + 1;
                $url = $data['url'];

                // Incrementa o contador no Firestore dentro da transação
                $transaction->update($docRef, [
                    ['path' => 'clicks', 'value' => $newClicks]
                ]);

                // Edita a mensagem original do bot para mostrar o novo número de cliques
                // O botão continua a ter o URL original, mas o texto é atualizado
                $keyboard = Keyboard::make()->inline()
                    ->row([
                        Keyboard::inlineButton(['text' => "Cliques: " . $newClicks, 'url' => $url, 'callback_data' => $linkId])
                    ]);

                $telegram->editMessageText([
                    'chat_id'      => $chatId,
                    'message_id'   => $messageId,
                    'text'         => '🔗 **Novo Link Rastreado!** Clique no botão abaixo para acessar:',
                    'reply_markup' => $keyboard,
                    'parse_mode'   => 'Markdown'
                ]);

                error_log("Clique rastreado para Link ID: {$linkId}. Total de cliques: {$newClicks}.");

            } else {
                error_log("Erro: Link ID '{$linkId}' não encontrado no Firestore.");
            }

            // Responde à callback query para remover o "relógio de espera" e dar feedback ao usuário
            $telegram->answerCallbackQuery([
                'callback_query_id' => $queryId,
                'text'              => 'Contador atualizado! (' . $newClicks . ' cliques)', // Mensagem pop-up discreta
                'show_alert'        => false, // 'false' para pop-up discreto; 'true' para um alerta maior
                'cache_time'        => 0 // Não cachear a resposta
            ]);
        });
    }

} catch (Exception $e) {
    // Tratamento de Erros: Registra quaisquer exceções que ocorram no processamento
    error_log("Erro Crítico no Webhook: " . $e->getMessage() . " - Trace: " . $e->getTraceAsString());
}

// 5. Resposta HTTP 200 OK para o Telegram:
// É crucial que a função sempre retorne um status 200 OK para o Telegram
// para evitar que ele reenvie a mesma atualização em loop.
http_response_code(200);
header("Content-Type: application/json");
echo json_encode(['status' => 'ok']);
die();

