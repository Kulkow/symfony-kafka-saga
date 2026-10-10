<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Kafka;

use RdKafka\Conf;
use RdKafka\KafkaConsumer;
use RdKafka\Producer;

final readonly class KafkaClientFactory
{
    public function __construct(
        private string $bootstrapServers,
        private string $consumerGroup,
    ) {
    }

    public function createProducer(?callable $deliveryReport = null): Producer
    {
        $config = $this->configuration();
        $config->set('enable.idempotence', 'true');
        $config->set('acks', 'all');
        $config->set('message.timeout.ms', '10000');
        if ($deliveryReport !== null) {
            $config->setDrMsgCb($deliveryReport);
        }

        return new Producer($config);
    }

    public function createConsumer(?string $group = null): KafkaConsumer
    {
        $config = $this->configuration();
        $config->set('group.id', $group ?? $this->consumerGroup);
        $config->set('enable.auto.commit', 'false');
        $config->set('enable.auto.offset.store', 'false');
        $config->set('auto.offset.reset', 'earliest');

        return new KafkaConsumer($config);
    }

    private function configuration(): Conf
    {
        $config = new Conf();
        $config->set('bootstrap.servers', $this->bootstrapServers);
        $config->set('allow.auto.create.topics', 'false');

        return $config;
    }
}
