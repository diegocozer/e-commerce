import type { AdminMe } from '@/shared/api/types';
import * as seed from './seed';

export function createDb() {
  return {
    me: seed.makeMe() as AdminMe,
    loggedIn: true,
    categories: seed.seedCategories(),
    brands: seed.seedBrands(),
    products: seed.seedProducts(),
    movements: seed.seedMovements(),
    orders: seed.seedOrders(),
    customers: seed.seedCustomers(),
    priceLists: seed.seedPriceLists(),
    tiers: seed.seedTiers(),
    customerPrices: seed.seedCustomerPrices(),
    promotions: seed.seedPromotions(),
    coupons: seed.seedCoupons(),
    carriers: seed.seedCarriers(),
    methods: seed.seedMethods(),
    zones: seed.seedZones(),
    rules: seed.seedRules(),
    roles: seed.seedRoles(),
    users: seed.seedUsers(),
    settings: seed.seedSettings(),
    audit: seed.seedAudit(),
    seq: 1000,
  };
}

export type MockDb = ReturnType<typeof createDb>;
export let db: MockDb = createDb();
export function resetDb(): MockDb {
  db = createDb();
  return db;
}
export const nextId = () => ++db.seq;
