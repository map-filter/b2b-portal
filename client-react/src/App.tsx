import { Navigate, Route, Routes } from 'react-router'
import { useAuth } from './auth/context'
import { Layout } from './components/Layout'
import { Spinner } from './components/ui'
import { CartPage } from './pages/CartPage'
import { CatalogPage } from './pages/CatalogPage'
import { CompanyPage } from './pages/CompanyPage'
import { LoginPage } from './pages/LoginPage'
import { OrderPage } from './pages/OrderPage'
import { OrdersPage } from './pages/OrdersPage'
import { ProductPage } from './pages/ProductPage'

export default function App() {
  const { user, loading } = useAuth()

  if (loading) return <Spinner className="min-h-screen items-center" />

  return (
    <Routes>
      <Route path="/login" element={<LoginPage />} />
      <Route element={user?.company ? <Layout /> : <Navigate to="/login" replace />}>
        <Route index element={<CatalogPage />} />
        <Route path="product/:slug" element={<ProductPage />} />
        <Route path="cart" element={<CartPage />} />
        <Route path="orders" element={<OrdersPage />} />
        <Route path="orders/:id" element={<OrderPage />} />
        <Route path="company" element={<CompanyPage />} />
      </Route>
      <Route path="*" element={<Navigate to="/" replace />} />
    </Routes>
  )
}
