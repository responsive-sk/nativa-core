import type { IAuthor } from '../../types/index.js';

export class Article {
  id: string;
  title: string;
  content: string;
  author: IAuthor;
  publishedAt: Date | null;

  constructor(
    id: string,
    title: string,
    content: string,
    author: IAuthor,
    publishedAt: Date | null
  ) {
    if (!id) throw new Error('Article must have an ID');
    if (!title || title.trim().length === 0) throw new Error('Article must have a title');
    this.id = id;
    this.title = title;
    this.content = content;
    this.author = author;
    this.publishedAt = publishedAt;
  }

  isPublished(): boolean {
    return this.publishedAt !== null;
  }

  canBeEditedBy(userId: string): boolean {
    return this.author.id === userId;
  }
}
