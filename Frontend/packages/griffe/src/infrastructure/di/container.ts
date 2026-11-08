type Factory = (container: Container) => any;

export class Container {
  private _services: Map<string, Factory>;

  constructor() {
    this._services = new Map();
  }

  register(name: string, factory: Factory): void {
    this._services.set(name, factory);
  }

  get<T = any>(name: string): T {
    const factory = this._services.get(name);
    if (!factory) throw new Error(`Service '${name}' not found`);
    return factory(this);
  }
}
