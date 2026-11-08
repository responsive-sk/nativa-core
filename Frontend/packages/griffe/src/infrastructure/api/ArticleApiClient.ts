import type { IArticle } from '../../types/index.js';

/**
 * API Client for Article endpoints
 * Infrastructure layer - communicates with backend
 */
export class ArticleApiClient {
  private baseUrl: string;

  constructor(baseUrl: string = 'http://localhost:8000/api') {
    this.baseUrl = baseUrl;
  }

  /**
   * Fetch all articles
   */
  async fetchArticles(): Promise<IArticle[]> {
    try {
      const response = await fetch(`${this.baseUrl}/articles`);

      if (!response.ok) {
        throw new Error(`HTTP error! status: ${response.status}`);
      }

      const data = await response.json();
      console.log('📚 Fetched articles:', data);
      return Array.isArray(data) ? data : [];
    } catch (error) {
      console.error('❌ Failed to fetch articles:', error);
      throw error;
    }
  }

  /**
   * Fetch single article by ID
   */
  async fetchArticle(id: string | number): Promise<IArticle> {
    try {
      const response = await fetch(`${this.baseUrl}/articles/${id}`);

      if (!response.ok) {
        throw new Error(`HTTP error! status: ${response.status}`);
      }

      const data = await response.json();
      console.log('📄 Fetched article:', data);
      return data;
    } catch (error) {
      console.error(`❌ Failed to fetch article ${id}:`, error);
      throw error;
    }
  }

  /**
   * Create new article (requires authentication)
   */
  async createArticle(articleData: Partial<IArticle>): Promise<IArticle> {
    try {
      const response = await fetch(`${this.baseUrl}/articles`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
        },
        body: JSON.stringify(articleData),
      });

      if (!response.ok) {
        throw new Error(`HTTP error! status: ${response.status}`);
      }

      return await response.json();
    } catch (error) {
      console.error('❌ Failed to create article:', error);
      throw error;
    }
  }
}
