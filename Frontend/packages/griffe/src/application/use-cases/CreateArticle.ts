import { Article } from '../../domain/entities/Article.js';
import { ArticleTitle } from '../../domain/value-objects/ArticleTitle.js';
import type { IAuthor } from '../../types/index.js';

interface ArticleRepository {
  save(article: Article): Promise<void>;
}

export class CreateArticle {
  private articleRepository: ArticleRepository;
  private currentUser: IAuthor;

  constructor(articleRepository: ArticleRepository, currentUser: IAuthor) {
    this.articleRepository = articleRepository;
    this.currentUser = currentUser;
  }

  async execute(title: string, content: string): Promise<Article> {
    const articleTitle = new ArticleTitle(title);
    const article = new Article(
      crypto.randomUUID(),
      articleTitle.value,
      content,
      this.currentUser,
      null
    );
    await this.articleRepository.save(article);
    return article;
  }
}
