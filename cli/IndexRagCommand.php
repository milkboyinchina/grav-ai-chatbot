<?php
namespace Grav\Plugin\Console;

use Grav\Console\ConsoleCommand;
use Grav\Plugin\AiChatbot\Rag\Indexer;
use Symfony\Component\Console\Input\InputOption;

/**
 * Class IndexRagCommand
 * Grav CLI command to trigger RAG page indexing and SQLite vector store rebuilds.
 *
 * Usage: php bin/plugin ai-chatbot index-rag [--rebuild]
 *
 * @license GPL-3.0-or-later
 */
class IndexRagCommand extends ConsoleCommand
{
    protected function configure(): void
    {
        $this
            ->setName('index-rag')
            ->setAliases(['indexrag'])
            ->setDescription('Parses published site pages and updates the RAG SQLite vector index database.')
            ->addOption(
                'rebuild',
                'r',
                InputOption::VALUE_NONE,
                'Clear existing SQLite index chunks and perform a complete rebuild from scratch'
            )
            ->setHelp('The <info>index-rag</info> command parses Grav pages, calculates heading-aware section chunks, generates embeddings, and saves vectors to rag_index.sqlite.');
    }

    protected function serve(): int
    {
        $forceRebuild = (bool)$this->input->getOption('rebuild');

        $this->output->writeln('<info>🧠 Starting RAG Page Indexing Process...</info>');
        // Register autoloader for plugin classes in CLI context
        spl_autoload_register(function ($class) {
            $prefix = 'Grav\\Plugin\\AiChatbot\\';
            $baseDir = __DIR__ . '/../classes/';
            $len = strlen($prefix);
            if (strncmp($prefix, $class, $len) !== 0) return;
            $relativeClass = substr($class, $len);
            $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';
            if (file_exists($file)) require_once $file;
        });

        $grav = \Grav\Common\Grav::instance();
        if (isset($grav['config']) && method_exists($grav['config'], 'init')) {
            $grav['config']->init();
        }
        if (isset($grav['plugins']) && method_exists($grav['plugins'], 'init')) {
            $grav['plugins']->init();
        }

        $config = $grav['config']->get('plugins.ai-chatbot') ?: [];
        $indexer = new Indexer($grav, $config);

        $result = $indexer->runFullIndex($forceRebuild);

        if (!empty($result['success'])) {
            $this->output->writeln('<info>✅ RAG Indexing Completed Successfully!</info>');
            $this->output->writeln('   • Indexed Chunks : ' . ($result['indexed_chunks'] ?? 0));
            $this->output->writeln('   • Skipped Chunks : ' . ($result['skipped_chunks'] ?? 0));
            $this->output->writeln('   • Total Chunks   : ' . ($result['total_chunks'] ?? 0));
            $this->output->writeln('   • Status Message : ' . ($result['message'] ?? 'OK'));
            return 0;
        } else {
            $this->output->writeln('<error>❌ RAG Indexing Failed:</error>');
            $this->output->writeln('   ' . ($result['error'] ?? $result['message'] ?? 'Unknown error occurred.'));
            return 1;
        }
    }
}
