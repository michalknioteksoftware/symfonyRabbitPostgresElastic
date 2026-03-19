<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Article;
use App\Form\ArticleFormType;
use App\Message\ArticlePublishedMessage;
use App\Repository\ArticleRepository;
use App\Service\ElasticsearchService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Cache\CacheInterface;

#[Route('/articles', name: 'article_web_')]
final class ArticleWebController extends AbstractController
{
    public function __construct(
        private readonly ArticleRepository   $articles,
        private readonly MessageBusInterface $bus,
        private readonly CacheInterface      $cache,
        private readonly ElasticsearchService $elasticsearch,
    ) {
    }

    // ── LIST ──────────────────────────────────────────────────────────────────
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $page       = max(1, $request->query->getInt('page', 1));
        $filterOnly = $request->query->get('status', 'all');

        // ── PostgreSQL full-text search (all statuses) ──
        $dbQuery = trim((string) $request->query->get('q', ''));

        if ($dbQuery !== '') {
            $articleList = $this->articles->search($dbQuery);
            $paginator   = null;
        } else {
            $paginator   = $this->articles->findPaginated($page, filterStatus: $filterOnly);
            $articleList = iterator_to_array($paginator);
        }

        // ── Elasticsearch full-text search (published only) ──
        $esQuery  = trim((string) $request->query->get('es_q', ''));
        $esResult = ['hits' => [], 'total' => 0, 'error' => null];

        if ($esQuery !== '') {
            $esResult = $this->elasticsearch->searchArticles($esQuery);
        }

        return $this->render('article/index.html.twig', [
            // DB section
            'articles'    => $articleList,
            'paginator'   => $paginator,
            'page'        => $page,
            'filterOnly'  => $filterOnly,
            'search'      => $dbQuery,
            'counts'      => $this->articles->countByStatus(),
            // ES section
            'esQuery'     => $esQuery,
            'esHits'      => $esResult['hits'],
            'esTotal'     => $esResult['total'],
            'esError'     => $esResult['error'],
        ]);
    }

    // ── SHOW ──────────────────────────────────────────────────────────────────
    #[Route('/{id}', name: 'show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id): Response
    {
        $article = $this->articles->find($id);
        if (!$article) {
            throw $this->createNotFoundException('Article not found.');
        }

        return $this->render('article/show.html.twig', ['article' => $article]);
    }

    // ── NEW ───────────────────────────────────────────────────────────────────
    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $article = new Article();
        $form    = $this->createForm(ArticleFormType::class, $article);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Sync the comma-separated tags from the unmapped field into the entity.
            $tagsRaw = $form->get('tagsRaw')->getData();
            if ($tagsRaw !== null) {
                $article->setTags(array_values(array_filter(
                    array_map('trim', explode(',', (string) $tagsRaw)),
                    fn (string $t) => $t !== ''
                )));
            }

            $this->articles->save($article);
            $this->cache->delete('articles_list_p1_pp10');

            // addFlash() stores a one-time message in the session.
            // It is read and removed by the next template that calls app.flashes().
            $this->addFlash('success', sprintf('Article "%s" created successfully.', $article->getTitle()));

            // PRG — redirect after POST prevents double-submit on browser refresh.
            return $this->redirectToRoute('article_web_show', ['id' => $article->getId()]);
        }

        return $this->render('article/new.html.twig', ['form' => $form]);
    }

    // ── EDIT ──────────────────────────────────────────────────────────────────
    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(int $id, Request $request): Response
    {
        $article = $this->articles->find($id);
        if (!$article) {
            throw $this->createNotFoundException('Article not found.');
        }

        // Pre-fill the unmapped tagsRaw field with the current tags as CSV.
        $form = $this->createForm(ArticleFormType::class, $article);
        $form->get('tagsRaw')->setData(implode(', ', $article->getTags()));

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $tagsRaw = $form->get('tagsRaw')->getData();
            if ($tagsRaw !== null) {
                $article->setTags(array_values(array_filter(
                    array_map('trim', explode(',', (string) $tagsRaw)),
                    fn (string $t) => $t !== ''
                )));
            }

            $this->articles->save($article);
            $this->cache->delete('article_'.$id);
            $this->cache->delete('articles_list_p1_pp10');

            $this->addFlash('success', 'Article updated successfully.');

            return $this->redirectToRoute('article_web_show', ['id' => $article->getId()]);
        }

        return $this->render('article/edit.html.twig', ['form' => $form, 'article' => $article]);
    }

    // ── DELETE ────────────────────────────────────────────────────────────────
    // DELETE uses POST + CSRF token so it works from a regular HTML button
    // (browsers can't send DELETE from a form). The CSRF check prevents CSRF attacks.
    #[Route('/{id}/delete', name: 'delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(int $id, Request $request): Response
    {
        $article = $this->articles->find($id);
        if (!$article) {
            throw $this->createNotFoundException('Article not found.');
        }

        // isCsrfTokenValid() checks the hidden _token field in the form.
        if (!$this->isCsrfTokenValid('delete-article-'.$id, (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid security token.');
            return $this->redirectToRoute('article_web_index');
        }

        $title = $article->getTitle();
        $this->articles->remove($article);
        $this->cache->delete('article_'.$id);
        $this->cache->delete('articles_list_p1_pp10');

        $this->addFlash('success', sprintf('Article "%s" deleted.', $title));

        return $this->redirectToRoute('article_web_index');
    }

    // ── PUBLISH ───────────────────────────────────────────────────────────────
    #[Route('/{id}/publish', name: 'publish', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function publish(int $id, Request $request): Response
    {
        $article = $this->articles->find($id);
        if (!$article) {
            throw $this->createNotFoundException('Article not found.');
        }

        if (!$this->isCsrfTokenValid('publish-article-'.$id, (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid security token.');
            return $this->redirectToRoute('article_web_show', ['id' => $id]);
        }

        if ($article->isPublished()) {
            $this->addFlash('warning', 'Article is already published.');
            return $this->redirectToRoute('article_web_show', ['id' => $id]);
        }

        $article->publish();
        $this->articles->save($article);
        $this->cache->delete('article_'.$id);
        $this->cache->delete('articles_list_p1_pp10');

        // Dispatch to RabbitMQ — handler indexes in Elasticsearch in the background.
        $this->bus->dispatch(new ArticlePublishedMessage($id, $article->getTitle()));

        $this->addFlash('success', 'Article published. Indexing in Elasticsearch in the background.');

        return $this->redirectToRoute('article_web_show', ['id' => $id]);
    }
}
