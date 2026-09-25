import { authHandlers } from './auth';
import { catalogHandlers } from './catalog';
import { commerceHandlers } from './commerce';
import { orderHandlers } from './orders';
import { shippingHandlers } from './shipping';
import { systemHandlers } from './system';

export const handlers = [...authHandlers, ...catalogHandlers, ...orderHandlers, ...commerceHandlers, ...shippingHandlers, ...systemHandlers];
