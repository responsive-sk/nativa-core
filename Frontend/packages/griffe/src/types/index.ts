import { LitElement } from 'lit';

// Lit component type helpers
export interface LitElementConstructor {
  new (): LitElement;
}

// Article domain types
export interface IArticle {
  id: string;
  title: string;
  content: string;
  author: IAuthor;
  publishedAt: Date | null;
}

export interface IAuthor {
  id: string;
  name: string;
}

// API response types
export interface ApiResponse<T> {
  data: T;
  error?: string;
}
