<?php

declare(strict_types=1);

namespace App\Content;

use App\Models\Project;

/**
 * The line at the end of an article that says AI helped write it.
 *
 * Fixed text rather than something the model writes, so it says the same thing
 * every time and nothing can soften it. It names the brand as the publisher
 * responsible for the content, which is what a reader is owed and what the EU
 * AI Act's exception for edited, attributed text asks for.
 *
 * In the article's own language where we have it, and in English otherwise —
 * a correct sentence in the wrong language is better than a wrong one.
 */
final class AiDisclosure
{
    /** @var array<string, string> */
    private const array LINES = [
        'en' => 'This article was written with the help of AI and published by :brand, who is responsible for its content.',
        'pt' => 'Este artigo foi escrito com a ajuda de IA. Responsável pelo conteúdo: :brand.',
        'es' => 'Este artículo se ha redactado con ayuda de IA. Responsable del contenido: :brand.',
        'fr' => 'Cet article a été rédigé avec l’aide de l’IA. Responsable du contenu : :brand.',
        'de' => 'Dieser Artikel wurde mit Unterstützung von KI erstellt. Verantwortlich für den Inhalt: :brand.',
        'it' => 'Questo articolo è stato scritto con l’aiuto dell’IA. Responsabile del contenuto: :brand.',
        'nl' => 'Dit artikel is geschreven met behulp van AI. Verantwoordelijk voor de inhoud: :brand.',
        'pl' => 'Ten artykuł powstał z pomocą AI. Za treść odpowiada :brand.',
        'uk' => 'Цю статтю написано за допомогою ШІ. Відповідальність за зміст: :brand.',
        'ru' => 'Эта статья написана с помощью ИИ. Ответственность за содержание: :brand.',
    ];

    /** The sentence, for this project in this language, or null when it is off. */
    public function lineFor(Project $project, string $locale): ?string
    {
        if (! $project->ai_disclosure) {
            return null;
        }

        $language = strtolower(explode('-', str_replace('_', '-', $locale))[0]);
        $template = self::LINES[$language] ?? self::LINES['en'];

        return str_replace(':brand', $this->escaped($project->name), $template);
    }

    /**
     * The article with the line at the end, once.
     *
     * Idempotent, because a rewrite starts from the model's draft but an edit
     * by hand starts from a body that already ends with it.
     */
    public function appendTo(string $markdown, Project $project, string $locale): string
    {
        $line = $this->lineFor($project, $locale);

        if ($line === null || str_contains($markdown, $line)) {
            return $markdown;
        }

        return rtrim($markdown)."\n\n---\n\n*".$line."*\n";
    }

    /** A brand name is text, not markup. */
    private function escaped(string $name): string
    {
        return (string) preg_replace('/([\\\\`*_\[\]<>#|])/u', '\\\\$1', trim($name));
    }
}
