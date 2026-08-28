<?php

namespace App\Controller;

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

    #[Route('/send', name: 'chat_send', methods: ['POST'])]
    public function send(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $author = $request->request->get('author');
        $content = $request->request->get('content');

        if(!$author || !$content)
        {
            return new JsonResponse(['status' => 'error', 'message' => 'No data'], 400);
        }

        $message = new Message();
        $message->setAuthor($author);
        $message->setContent($content);
        $message->setCreatedAt(new \DateTimeImmutable('now', new \DateTimeZone('Europe/Warsaw')));

        $em->persist($message);
        $em->flush();

        return new JsonResponse(['status' => 'sent']);
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
                        'time' => $msg->getCreatedAt()->format('H:i:s')
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