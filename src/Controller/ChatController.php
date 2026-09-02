<?php

namespace App\Controller;

use App\Entity\Conversation;
use App\Entity\Message;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

class ChatController extends AbstractController
{
    #[Route('/', name: 'chat_index')]
    public function index(): Response
    {
        return $this->render('chat/index.html.twig');
    }

    #[Route('/conversations', name: 'chat_conversations', methods: ['GET'])]
    public function getConversations(EntityManagerInterface $em): JsonResponse
    {
        $conversations = $em->getRepository(Conversation::class)
            ->findBy([], ['created_at' => 'DESC']);
        
        $data = array_map(fn($c) => [
            'id' => $c->getId(),
            'title' => $c->getTitle(),
            'createdAt' => $c->getCreatedAt()->format('Y-m-d H:i')
        ], $conversations);

        return new JsonResponse($data);
    }

    #[Route('/conversations/new', name: 'chat_new_conversation', methods: ['POST'])]
    public function newConversation(EntityManagerInterface $em): JsonResponse
    {
        $conversation = new Conversation();
        $conversation->setTitle('New chat');
        $conversation->setCreatedAt(new \DateTimeImmutable('now', new \DatetimeZone('Europe/Warsaw')));

        $em->persist($conversation);
        $em->flush();

        return new JsonResponse([
            'id' => $conversation->getId(),
            'title' => $conversation->getTitle()
        ]);
    }

    #[Route('/conversations/{id}/messages', name: 'chat_get_messages', methods: ['GET'])]
    public function getMessages(Conversation $conversation): JsonResponse
    {
        $messages = array_map(fn($m) => [
            'id' => $m->getId(),
            'author' => $m->getAuthor(),
            'content' => $m->getContent(),
            'time' => $m->getCreatedAt()->format('H:i:s')
        ], $conversation->getMessages()->toArray());

        return new JsonResponse($messages);
    }

    #[Route('/conversations/{id}', name: 'chat_delete_conversation', methods: ['DELETE'])]
    public function deleteConversation(conversation $conversation, EntityManagerInterface $em): JsonResponse
    {
        $em->remove($conversation);
        $em->flush();

        return new JsonResponse(['status' => 'deleted', 'id' => $conversation->getId()]);
    }

    #[Route('/send', name: 'chat_send', methods: ['POST'])]
    public function send(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $conversationId = $request->request->get('conversation_id');
        $author = $request->request->get('author');
        $content = $request->request->get('content');

        if(!$conversationId || !$author || !$content)
            return new JsonResponse(['status' => 'error', 'message' => 'No data'], 400);

        $conversation = $em->getRepository(Conversation::class)->find($conversationId);
        if(!$conversation)
            return new JsonResponse(['status' => 'error', 'message' => 'No chat found'], 404);

        if($conversation->getMessages()->isEmpty())
        {
            $title = mb_substr($content, 0, 30);
            if(mb_strlen($content) > 30) $title .= '...';
            $conversation->setTitle($title);
        }

        $message = new Message();
        $message->setConversation($conversation);
        $message->setAuthor($author);
        $message->setContent($content);
        $message->setCreatedAt(new \DateTimeImmutable('now', new \DateTimeZone('Europe/Warsaw')));

        $em->persist($message);
        $em->flush();

        return new JsonResponse(['status' => 'sent', 'conversationTitle' => $conversation->getTitle()]);
    }

    #[Route('/stream', name: 'chat_stream')]
    public function streamMessages(EntityManagerInterface $em): StreamedResponse
    {
        $response = new StreamedResponse(function () use ($em)
        {
            $lastId = 0;

            while(true)
            {
                $maxId = $em->createQueryBuilder()
                    ->select('MAX(m.id)')
                    ->from(Message::class, 'm')
                    ->getQuery()
                    ->getSingleScalarResult();

                if($maxId === null || (int)$maxId < $lastId)
                {
                    if($lastId > 0)
                    {
                        echo "data: " . json_encode(['action' => 'clear']) . "\n\n";
                        if(ob_get_level() > 0 )
                            ob_flush();
                        flush();
                        $lastId = 0;
                    }

                    $em->clear();
                    if(connection_aborted())
                        break;
                    sleep(1);
                    continue;
                }
                
                $messages = $em->getRepository(Message::class)
                    ->createQueryBuilder('m')
                    ->where('m.id > :lastId')
                    ->setParameter('lastId', $lastId)
                    ->orderBy('m.id', 'ASC')
                    ->getQuery()
                    ->getResult();
                
                foreach($messages as $msg)
                {
                    $data = json_encode([
                        'id' => $msg->getId(),
                        'author' => $msg->getAuthor(),
                        'content' => $msg->getContent(),
                        'time' => $msg->getCreatedAt()->format('H:i:s'),
                        'conversation_id' => $msg->getConversation() ? $msg->getConversation()->getId() : null
                    ]);

                    echo "data: {$data}\n\n";
                    $lastId = $msg->getId();

                    if(ob_get_level() > 0)
                        ob_flush();
                    flush();
                }
                
                $em->clear();
                
                if(connection_aborted())
                    break;

                sleep(1);
            }
        });

        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache');
        $response->headers->set('Connection', 'keep-alive');
        $response->headers->set('X-Accel-Buffering', 'no');

        return $response;
    }

    #[Route('/clear', name: 'chat_clear', methods: ['DELETE'])]
    public function clear(EntityManagerInterface $em): JsonResponse
    {
        $connection = $em->getConnection();
        $connection->executeStatement('TRUNCATE TABLE Message RESTART IDENTITY CASCADE');

        return new JsonResponse(['status' => 'ok']);
    }

}