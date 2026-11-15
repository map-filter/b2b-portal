<?php

namespace App\Services\Messaging;

use Closure;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use Throwable;

class RabbitMq implements MessageBus
{
    public const CATALOG_EXCHANGE = 'b2b.catalog';

    public const EVENTS_EXCHANGE = 'b2b.events';

    private ?AMQPStreamConnection $connection = null;

    private ?AMQPChannel $channel = null;

    public function publish(string $exchange, string $routingKey, array $payload): void
    {
        $channel = $this->channel();
        $channel->basic_publish(new AMQPMessage(
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ['content_type' => 'application/json', 'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT],
        ), $exchange, $routingKey);
        $channel->wait_for_pending_acks_returns(5);
    }

    /**
     * Ошибочное сообщение уходит в очередь {queue}.dead и не блокирует остальные.
     *
     * @param  list<string>  $bindings
     * @param  Closure(string, array): void  $handler
     */
    public function consume(string $queue, string $exchange, array $bindings, Closure $handler, ?Closure $onError = null): void
    {
        $channel = $this->channel();

        $channel->queue_declare("{$queue}.dead", false, true, false, false);
        $channel->queue_declare($queue, false, true, false, false, false, new AMQPTable([
            'x-dead-letter-exchange' => '',
            'x-dead-letter-routing-key' => "{$queue}.dead",
        ]));
        foreach ($bindings as $binding) {
            $channel->queue_bind($queue, $exchange, $binding);
        }
        $channel->basic_qos(0, 1, false);

        $channel->basic_consume($queue, '', false, false, false, false, function (AMQPMessage $message) use ($handler, $onError) {
            try {
                $handler($message->getRoutingKey(), json_decode($message->getBody(), true, flags: JSON_THROW_ON_ERROR));
                $message->ack();
            } catch (Throwable $exception) {
                $message->nack(false);
                $onError?->__invoke($message->getRoutingKey(), $exception);
            }
        });

        while ($channel->is_consuming()) {
            $channel->wait();
        }
    }

    private function channel(): AMQPChannel
    {
        if ($this->channel?->is_open()) {
            return $this->channel;
        }

        $config = config('services.rabbitmq');
        $this->connection = new AMQPStreamConnection($config['host'], $config['port'], $config['user'], $config['password']);
        $this->channel = $this->connection->channel();
        $this->channel->confirm_select();

        foreach ([self::CATALOG_EXCHANGE, self::EVENTS_EXCHANGE] as $exchange) {
            $this->channel->exchange_declare($exchange, 'topic', false, true, false);
        }

        return $this->channel;
    }

    public function __destruct()
    {
        try {
            $this->channel?->close();
            $this->connection?->close();
        } catch (Throwable) {
        }
    }
}
